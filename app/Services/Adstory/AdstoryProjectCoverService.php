<?php

namespace App\Services\Adstory;

use App\Models\AdstoryProject;
use App\Models\AdstoryShot;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AdstoryProjectCoverService
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly AdstoryGeminiContentService $contentService,
    ) {}

    /**
     * Generate a dedicated story-related cover for the project card.
     * Skips when a cover already exists unless $force is true.
     *
     * @return array{cover_image_url: string|null, generated: bool, skipped: bool}
     */
    public function ensureCoverImage(AdstoryProject $project, bool $force = false): array
    {
        $existing = trim((string) ($project->cover_image_url ?? ''));

        if (! $force && $existing !== '') {
            return [
                'cover_image_url' => $existing,
                'generated' => false,
                'skipped' => true,
            ];
        }

        $story = trim((string) ($project->story ?? ''));

        if ($story === '') {
            throw new RuntimeException('Project has no story to base a cover image on.');
        }

        $prompt = $this->buildCoverPrompt($project, $story);

        Log::info('Adstory project cover: generating', [
            'project_id' => $project->id,
            'force' => $force,
        ]);

        try {
            $imageBase64 = $this->geminiService->generateImage($prompt);
            $imageData = base64_decode($imageBase64, true);

            if ($imageData === false || $imageData === '') {
                throw new RuntimeException('Gemini cover generation returned invalid image data.');
            }

            $storagePath = 'adstory/projects/'.$project->id.'/cover/'.uniqid('cover_', true).'.png';
            Storage::disk('public')->put($storagePath, $imageData);
            $imageUrl = $this->contentService->publicStorageUrl($storagePath);

            $project->cover_image_url = $imageUrl;
            $project->save();

            Log::info('Adstory project cover: saved', [
                'project_id' => $project->id,
                'cover_image_url' => $imageUrl,
            ]);

            return [
                'cover_image_url' => $imageUrl,
                'generated' => true,
                'skipped' => false,
            ];
        } catch (Throwable $e) {
            Log::error('Adstory project cover: failed', [
                'project_id' => $project->id,
                'message' => $e->getMessage(),
            ]);

            $fallback = $this->applyFallbackCoverFromStoryboard($project);

            if ($fallback !== null) {
                Log::info('Adstory project cover: using storyboard shot fallback', [
                    'project_id' => $project->id,
                    'cover_image_url' => $fallback,
                ]);

                return [
                    'cover_image_url' => $fallback,
                    'generated' => true,
                    'skipped' => false,
                ];
            }

            throw $e;
        }
    }

    /**
     * Use the earliest completed storyboard shot as a card cover when Gemini is unavailable.
     */
    public function applyFallbackCoverFromStoryboard(AdstoryProject $project): ?string
    {
        $shot = AdstoryShot::query()
            ->where('adstory_project_id', $project->id)
            ->where('image_status', 'completed')
            ->whereNotNull('image_url')
            ->where('image_url', '!=', '')
            ->orderBy('order_index')
            ->orderBy('shot_number')
            ->orderBy('id')
            ->first();

        if (! $shot) {
            return null;
        }

        $imageUrl = trim((string) $shot->image_url);

        if ($imageUrl === '') {
            return null;
        }

        $project->cover_image_url = $imageUrl;
        $project->save();

        return $imageUrl;
    }

    /**
     * Backfill covers for projects that have a story but no cover yet.
     *
     * @return array{generated: int, skipped: int, failed: int, errors: list<array{project_id: int, message: string}>}
     */
    public function backfillMissingCovers(?int $projectId = null, bool $force = false): array
    {
        $query = AdstoryProject::query()
            ->whereNotNull('story')
            ->where('story', '!=', '')
            ->orderBy('id');

        if ($projectId !== null) {
            $query->where('id', $projectId);
        }

        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('cover_image_url')->orWhere('cover_image_url', '');
            });
        }

        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($query->cursor() as $project) {
            try {
                $result = $this->ensureCoverImage($project, $force);

                if ($result['skipped']) {
                    $skipped++;
                } elseif ($result['generated']) {
                    $generated++;
                }
            } catch (Throwable $e) {
                $failed++;
                $errors[] = [
                    'project_id' => (int) $project->id,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'generated' => $generated,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    private function buildCoverPrompt(AdstoryProject $project, string $story): string
    {
        $title = trim((string) ($project->title ?? ''));
        $style = trim((string) ($project->visual_style ?? ''));
        $storyExcerpt = mb_substr($story, 0, 1200);

        $parts = [
            'Create a single cinematic key-art poster / cover image for an original story.',
            'This is a project thumbnail that must clearly evoke the story world and mood.',
            'Wide landscape composition (about 16:9), dramatic lighting, no text, no logos, no watermarks, no UI elements.',
            'Do not render readable words or titles in the image.',
        ];

        if ($title !== '') {
            $parts[] = 'Project title (for theme only, do not write it in the image): '.$title;
        }

        if ($style !== '') {
            $parts[] = 'Visual style: '.$style;
        }

        $parts[] = "Story synopsis:\n".$storyExcerpt;

        return implode("\n\n", $parts);
    }
}
