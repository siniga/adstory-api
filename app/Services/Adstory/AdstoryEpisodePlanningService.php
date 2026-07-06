<?php

namespace App\Services\Adstory;

use App\Models\AdstoryEpisode;
use App\Models\AdstoryProject;
use App\Services\GeminiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdstoryEpisodePlanningService
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly AdstoryGeminiContentService $contentService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(AdstoryProject $project, bool $force = false): array
    {
        $screenplay = trim((string) ($project->screenplay ?? ''));

        if (mb_strlen($screenplay) < 20) {
            throw new RuntimeException('Project must have a screenplay before planning episodes.');
        }

        if ($project->episodes()->exists() && ! $force) {
            return $this->buildResponse($project->fresh(), started: false);
        }

        $prompt = $this->contentService->buildEpisodePlanningPrompt($screenplay);

        Log::info('Adstory episode-planning: Gemini request started', [
            'project_id' => $project->id,
        ]);

        $responseText = $this->geminiService->generateText($prompt);
        $plan = $this->contentService->parseEpisodePlanningJson($responseText);

        $estimatedCount = (int) $plan['estimated_scene_count'];
        $episodeSummaries = is_array($plan['episodes'] ?? null) ? $plan['episodes'] : [];
        $episodeCount = (int) max(1, ceil($estimatedCount / AdstoryEpisode::MAX_SCENES_PER_EPISODE));

        DB::transaction(function () use ($project, $estimatedCount, $episodeCount, $episodeSummaries) {
            if ($project->episodes()->exists()) {
                $project->episodes()->delete();
            }

            for ($i = 0; $i < $episodeCount; $i++) {
                $episodeNumber = $i + 1;
                $startScene = ($i * AdstoryEpisode::MAX_SCENES_PER_EPISODE) + 1;
                $endScene = min(($i + 1) * AdstoryEpisode::MAX_SCENES_PER_EPISODE, $estimatedCount);
                $scenesInEpisode = $endScene - $startScene + 1;
                $summary = $episodeSummaries[$i] ?? [];

                AdstoryEpisode::query()->create([
                    'adstory_project_id' => $project->id,
                    'episode_number' => $episodeNumber,
                    'title' => $summary['title'] ?? "Episode {$episodeNumber}",
                    'summary' => $summary['summary'] ?? null,
                    'estimated_scene_count' => $scenesInEpisode,
                    'start_scene_number' => $startScene,
                    'end_scene_number' => $endScene,
                    'status' => AdstoryEpisode::STATUS_PLANNED,
                ]);
            }

            $project->update(['current_step' => 'episodes']);
        });

        Log::info('Adstory episode-planning: episodes created', [
            'project_id' => $project->id,
            'estimated_scene_count' => $estimatedCount,
            'episode_count' => $episodeCount,
        ]);

        return $this->buildResponse($project->fresh(), started: true, estimatedSceneCount: $estimatedCount);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponse(AdstoryProject $project, bool $started, ?int $estimatedSceneCount = null): array
    {
        $episodes = $project->episodes()
            ->orderBy('episode_number')
            ->get()
            ->map(fn (AdstoryEpisode $episode) => $episode->toApiArray())
            ->values()
            ->all();

        $totalEstimated = $estimatedSceneCount ?? array_reduce(
            $episodes,
            fn (int $carry, array $episode) => $carry + (int) ($episode['estimated_scene_count'] ?? 0),
            0
        );

        return [
            'success' => true,
            'started' => $started,
            'estimated_scene_count' => $totalEstimated,
            'episode_count' => count($episodes),
            'episodes' => $episodes,
        ];
    }
}
