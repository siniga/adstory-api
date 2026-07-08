<?php

namespace App\Services\Adstory;

use App\Jobs\Adstory\GenerateShotImageJob;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use App\Services\GeminiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AdstoryShotImageJobService
{
    public const QUEUE_NAME = 'adstory-ai';

    public const JOB_TIMEOUT_SECONDS = 300;

    public const STALE_GENERATING_SECONDS = 300;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public function __construct(
        private readonly AdstoryShotImageService $shotImageService,
        private readonly AdstoryGeminiContentService $contentService,
        private readonly GeminiService $geminiService,
    ) {}

    /**
     * Queue one independent job for a shot. Returns false if skipped.
     */
    public function queueShotImageJob(
        AdstoryShot $shot,
        ?string $customPrompt = null,
        bool $force = false,
    ): bool {
        if (! $force && $this->shotHasCompletedImage($shot)) {
            return false;
        }

        if ($this->shotIsInFlight($shot) && ! $force) {
            return false;
        }

        $shot->markStoryboardImageQueued();

        Log::info('Queued Shot '.$shot->id, [
            'shot_id' => $shot->id,
            'project_id' => $shot->adstory_project_id,
            'scene_id' => $shot->adstory_scene_id,
        ]);

        GenerateShotImageJob::dispatch($shot->id, $customPrompt, $force);

        return true;
    }

    /**
     * @return int Number of jobs queued
     */
    public function queueShotImageJobsForScene(
        AdstoryProject $project,
        AdstoryScene $scene,
        bool $force = false,
        ?string $customPrompt = null,
    ): int {
        $queued = 0;

        $shots = AdstoryShot::query()
            ->where('adstory_project_id', $project->id)
            ->where('adstory_scene_id', $scene->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        foreach ($shots as $shot) {
            if ($this->queueShotImageJob($shot, $customPrompt, $force)) {
                $queued++;
            }
        }

        return $queued;
    }

    public function retryShot(AdstoryProject $project, AdstoryShot $shot): void
    {
        if ($shot->adstory_project_id !== $project->id) {
            throw new RuntimeException('Shot not found for this project.');
        }

        $shot->increment('image_retry_count');
        $shot->refresh();

        Log::info('Retry Shot '.$shot->id, [
            'shot_id' => $shot->id,
            'retry_count' => $shot->image_retry_count,
        ]);

        $this->queueShotImageJob($shot, force: true);
    }

    public function cancelInFlightShotsForScene(AdstoryProject $project, AdstoryScene $scene): int
    {
        $shots = AdstoryShot::query()
            ->where('adstory_project_id', $project->id)
            ->where('adstory_scene_id', $scene->id)
            ->whereIn('image_status', [self::STATUS_QUEUED, self::STATUS_GENERATING])
            ->get();

        foreach ($shots as $shot) {
            $shot->markStoryboardImageCancelled();
        }

        return $shots->count();
    }

    public function resetStaleGeneratingShots(?int $projectId = null, ?int $sceneId = null): int
    {
        $query = AdstoryShot::query()
            ->where('image_status', self::STATUS_GENERATING)
            ->where('image_generation_started_at', '<', now()->subSeconds(self::STALE_GENERATING_SECONDS));

        if ($projectId !== null) {
            $query->where('adstory_project_id', $projectId);
        }

        if ($sceneId !== null) {
            $query->where('adstory_scene_id', $sceneId);
        }

        $count = 0;

        foreach ($query->get() as $shot) {
            $shot->markStoryboardImageFailed('Image generation timed out.');
            $count++;

            Log::warning('Failed Shot '.$shot->id.' (stale timeout)', [
                'shot_id' => $shot->id,
                'project_id' => $shot->adstory_project_id,
            ]);
        }

        return $count;
    }

    /**
     * @return int Number of jobs queued for incomplete shots
     */
    public function ensureMissingShotImageJobs(
        AdstoryProject $project,
        ?AdstoryScene $scene = null,
        bool $includeFailed = false,
    ): int {
        $query = AdstoryShot::query()
            ->where('adstory_project_id', $project->id)
            ->orderBy('order_index')
            ->orderBy('id');

        if ($scene !== null) {
            $query->where('adstory_scene_id', $scene->id);
        }

        $queued = 0;

        foreach ($query->get() as $shot) {
            if ($this->shotHasCompletedImage($shot)) {
                continue;
            }

            if (! $includeFailed && $shot->image_status === self::STATUS_FAILED) {
                continue;
            }

            if ($this->shotIsInFlight($shot)) {
                continue;
            }

            if ($this->queueShotImageJob($shot)) {
                $queued++;
            }
        }

        return $queued;
    }

    public function executeShotImageGeneration(
        int $shotId,
        ?string $customPrompt = null,
        bool $force = false,
    ): void {
        $shot = AdstoryShot::query()->find($shotId);

        if (! $shot) {
            throw new RuntimeException('Shot no longer exists.');
        }

        if (! $force && $this->shotHasCompletedImage($shot)) {
            Log::info('Completed Shot '.$shot->id.' (already done, skipped)', [
                'shot_id' => $shot->id,
            ]);

            return;
        }

        if ($shot->image_status === self::STATUS_PENDING) {
            Log::info('Skipped Shot '.$shot->id.' (cancelled before start)', [
                'shot_id' => $shot->id,
            ]);

            return;
        }

        Log::info('Started Shot '.$shot->id, [
            'shot_id' => $shot->id,
            'project_id' => $shot->adstory_project_id,
            'scene_id' => $shot->adstory_scene_id,
        ]);

        try {
            $project = AdstoryProject::query()
                ->with(['characters', 'environments'])
                ->find($shot->adstory_project_id);

            if (! $project) {
                throw new RuntimeException('Project no longer exists.');
            }

            $shot->markStoryboardImageGenerating();
            $shot->update(['image_progress' => 15]);

            $shot->load('scene');
            $scene = $shot->scene;

            $characters = $this->shotImageService->resolveCharactersForShot($shot, $project);
            $environment = $this->shotImageService->resolveEnvironmentForShot($shot, $scene, $project);

            $promptResult = $this->shotImageService->buildShotImagePrompt(
                shot: $shot,
                scene: $scene,
                project: $project,
                characters: $characters,
                environment: $environment,
                customPrompt: $customPrompt,
            );

            $imagePrompt = $promptResult['prompt'];
            $shot->update(['image_prompt' => $imagePrompt, 'image_progress' => 30]);

            $imageBase64 = $this->geminiService->generateImage($imagePrompt);
            $shot->update(['image_progress' => 70]);

            $imageData = base64_decode($imageBase64, true);

            if ($imageData === false) {
                throw new RuntimeException('Gemini image generation failed: invalid base64 image data.');
            }

            $this->assertUsableImageData($imageData);

            $sceneId = $shot->adstory_scene_id ?? 'unknown';
            $storageSuffix = uniqid('storyboard_', true);
            $storagePath = "adstory/projects/{$project->id}/storyboard/scenes/{$sceneId}/shots/{$shot->id}/{$storageSuffix}.png";
            Storage::disk('public')->put($storagePath, $imageData);
            $imageUrl = $this->contentService->publicStorageUrl($storagePath);

            $shot->markStoryboardImageCompleted($imageUrl, $imagePrompt);

            Log::info('Completed Shot '.$shot->id, [
                'shot_id' => $shot->id,
                'project_id' => $project->id,
                'image_url' => $imageUrl,
            ]);
        } catch (Throwable $e) {
            $shot->markStoryboardImageFailed($e->getMessage());

            Log::error('Failed Shot '.$shot->id, [
                'shot_id' => $shot->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function markShotFailedFromJob(int $shotId, string $error): void
    {
        $shot = AdstoryShot::query()->find($shotId);

        if (! $shot || $shot->image_status === self::STATUS_COMPLETED) {
            return;
        }

        if ($shot->image_status !== self::STATUS_FAILED) {
            $shot->markStoryboardImageFailed($error);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function buildProjectGenerationProgress(AdstoryProject $project): array
    {
        $this->resetStaleGeneratingShots($project->id);

        $shots = AdstoryShot::query()
            ->where('adstory_project_id', $project->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        return $this->buildProgressFromShots($shots);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSceneGenerationProgress(AdstoryProject $project, AdstoryScene $scene): array
    {
        $this->resetStaleGeneratingShots($project->id, $scene->id);

        $shots = AdstoryShot::query()
            ->where('adstory_project_id', $project->id)
            ->where('adstory_scene_id', $scene->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        return $this->buildProgressFromShots($shots);
    }

    public function shotHasCompletedImage(AdstoryShot $shot): bool
    {
        return $shot->image_status === self::STATUS_COMPLETED && ! empty($shot->image_url);
    }

    public function shotIsInFlight(AdstoryShot $shot): bool
    {
        return in_array($shot->image_status, [self::STATUS_QUEUED, self::STATUS_GENERATING], true);
    }

    /**
     * @param  Collection<int, AdstoryShot>  $shots
     * @return array<string, mixed>
     */
    private function buildProgressFromShots(Collection $shots): array
    {
        $total = $shots->count();
        $queued = 0;
        $generating = 0;
        $completed = 0;
        $failed = 0;
        $currentJobs = [];

        foreach ($shots as $shot) {
            $status = $this->normalizeImageStatus($shot);

            match ($status) {
                self::STATUS_QUEUED => $queued++,
                self::STATUS_GENERATING => $generating++,
                self::STATUS_COMPLETED => $completed++,
                self::STATUS_FAILED => $failed++,
                default => null,
            };

            if (in_array($status, [self::STATUS_QUEUED, self::STATUS_GENERATING, self::STATUS_FAILED], true)) {
                $currentJobs[] = $this->mapShotJobState($shot, $status);
            }
        }

        $progressPercent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        return [
            'success' => true,
            'total_shots' => $total,
            'queued' => $queued,
            'generating' => $generating,
            'completed' => $completed,
            'failed' => $failed,
            'progress_percent' => $progressPercent,
            'current_jobs' => array_slice($currentJobs, 0, 25),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapShotJobState(AdstoryShot $shot, string $status): array
    {
        return [
            'shot_id' => $shot->id,
            'scene_id' => $shot->adstory_scene_id,
            'shot_number' => $shot->shot_number,
            'title' => $shot->title,
            'status' => $status,
            'progress' => (int) ($shot->image_progress ?? 0),
            'error_message' => $shot->generation_error,
            'retry_count' => (int) ($shot->image_retry_count ?? 0),
            'started_at' => $shot->image_generation_started_at?->toIso8601String(),
            'completed_at' => $shot->image_generation_completed_at?->toIso8601String(),
        ];
    }

    private function normalizeImageStatus(AdstoryShot $shot): string
    {
        $status = $shot->image_status ?? self::STATUS_PENDING;

        if ($status === self::STATUS_COMPLETED && empty($shot->image_url)) {
            return self::STATUS_PENDING;
        }

        return (string) $status;
    }

    private function assertUsableImageData(string $imageData): void
    {
        if (strlen($imageData) < 1024) {
            throw new RuntimeException('Gemini returned an unusable image: file too small or empty.');
        }

        $isPng = str_starts_with($imageData, "\x89PNG\r\n\x1a\n");
        $isJpeg = str_starts_with($imageData, "\xff\xd8\xff");

        if (! $isPng && ! $isJpeg) {
            throw new RuntimeException('Gemini returned an unusable image: invalid image format.');
        }
    }
}
