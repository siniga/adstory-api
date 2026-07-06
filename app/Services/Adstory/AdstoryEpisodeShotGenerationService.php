<?php

namespace App\Services\Adstory;

use App\Models\AdstoryAiTask;
use App\Models\AdstoryEpisode;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdstoryEpisodeShotGenerationService
{
    public function __construct(
        private readonly AdstoryAiTaskService $aiTaskService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startGeneration(AdstoryProject $project, AdstoryEpisode $episode, bool $force = false, ?string $style = null): array
    {
        $this->assertEpisodeBelongsToProject($episode, $project);
        $this->assertEpisodeScenesCompleted($episode);

        if ($this->episodeHasShots($episode) && ! $force) {
            return array_merge(
                $this->buildShotProgress($episode),
                ['started' => false],
            );
        }

        if ($this->hasActiveEpisodeShotTasks($project, $episode) && ! $force) {
            return array_merge(
                $this->buildShotProgress($episode),
                ['started' => false],
            );
        }

        $scenes = $episode->scenes()
            ->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        if ($scenes->isEmpty()) {
            throw new RuntimeException('Episode has no completed scenes for shot generation.');
        }

        DB::transaction(function () use ($project, $episode, $scenes, $force, $style) {
            if ($force) {
                $sceneIds = $scenes->pluck('id')->all();
                AdstoryShot::query()->whereIn('adstory_scene_id', $sceneIds)->delete();
                AdstoryAiTask::query()
                    ->where('adstory_project_id', $project->id)
                    ->where('type', AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE)
                    ->whereIn('taskable_id', $sceneIds)
                    ->delete();
            }

            $episode->update([
                'status' => AdstoryEpisode::STATUS_SHOTS_GENERATING,
                'shot_generation_status' => 'generating',
                'shot_generation_error' => null,
            ]);

            foreach ($scenes as $index => $scene) {
                $scene->markShotGenerationQueued();

                $this->aiTaskService->createTask(
                    project: $project,
                    type: AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE,
                    taskable: $scene,
                    payload: [
                        'project_id' => $project->id,
                        'episode_id' => $episode->id,
                        'scene_id' => $scene->id,
                        'scene_number' => $scene->scene_number,
                        'style' => $style ?? $project->visual_style,
                    ],
                    priority: 9000 - $index,
                );
            }
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory episode shot-generation: tasks created', [
            'project_id' => $project->id,
            'episode_id' => $episode->id,
            'scene_count' => $scenes->count(),
        ]);

        return array_merge(
            $this->buildShotProgress($episode->fresh()),
            ['started' => true],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildShotProgress(AdstoryEpisode $episode): array
    {
        $this->aiTaskService->resetStaleRunningTasks($episode->adstory_project_id, AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE);

        $episode->load(['scenes' => fn ($query) => $query
            ->select([
                'id', 'adstory_episode_id', 'title', 'scene_number', 'status',
                'shot_generation_status', 'order_index',
            ])
            ->with(['shots' => fn ($q) => $q->select([
                'id', 'adstory_scene_id', 'shot_number', 'title', 'description',
                'shot_size', 'camera_angle', 'camera_movement', 'composition',
                'lighting', 'meta', 'image_url', 'status', 'order_index',
            ])->orderBy('order_index')->orderBy('id')])
            ->orderBy('order_index')
            ->orderBy('id'),
        ]);

        $scenes = $episode->scenes;
        $totalScenes = $scenes->count();
        $completedScenes = $scenes->filter(
            fn (AdstoryScene $scene) => ($scene->shot_generation_status ?? '') === AdstoryShotGenerationService::SHOT_STATUS_COMPLETED
                || $scene->shots->isNotEmpty()
        )->count();
        $failedScenes = $scenes->where('shot_generation_status', AdstoryShotGenerationService::SHOT_STATUS_FAILED)->count();
        $remainingScenes = max(0, $totalScenes - $completedScenes - $failedScenes);
        $progressPercent = $totalScenes > 0 ? (int) round(($completedScenes / $totalScenes) * 100) : 0;

        $this->finalizeEpisodeIfDone($episode, $scenes);
        $episode->refresh();

        return [
            'success' => true,
            'episode' => $episode->toApiArray(),
            'total_scenes' => $totalScenes,
            'completed_scenes' => $completedScenes,
            'failed_scenes' => $failedScenes,
            'remaining_scenes' => $remainingScenes,
            'progress_percent' => $progressPercent,
            'scenes' => $scenes->map(function (AdstoryScene $scene) {
                return [
                    'id' => $scene->id,
                    'scene_number' => $scene->scene_number,
                    'title' => $scene->title,
                    'status' => $scene->status,
                    'shot_generation_status' => $scene->shot_generation_status,
                    'shots' => $scene->shots->map(function (AdstoryShot $shot) {
                        $shotMeta = is_array($shot->meta ?? null) ? $shot->meta : [];

                        return [
                            'id' => $shot->id,
                            'shot_number' => $shot->shot_number,
                            'title' => $shot->title,
                            'description' => $shot->description,
                            'shot_size' => $shot->shot_size,
                            'camera_angle' => $shot->camera_angle,
                            'camera_movement' => $shot->camera_movement,
                            'composition' => $shot->composition,
                            'lighting' => $shot->lighting,
                            'mood' => $shotMeta['mood'] ?? null,
                            'image_url' => $shot->image_url,
                            'status' => $shot->status,
                            'order_index' => $shot->order_index,
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildStoryboard(AdstoryEpisode $episode): array
    {
        $progress = $this->buildShotProgress($episode);

        return [
            'success' => true,
            'episode' => $progress['episode'],
            'shot_generation_summary' => [
                'total_scenes' => $progress['total_scenes'],
                'completed_scenes' => $progress['completed_scenes'],
                'failed_scenes' => $progress['failed_scenes'],
                'remaining_scenes' => $progress['remaining_scenes'],
                'progress_percent' => $progress['progress_percent'],
            ],
            'scenes' => $progress['scenes'],
        ];
    }

    public function finalizeEpisodeIfDone(AdstoryEpisode $episode, $scenes): void
    {
        if ($episode->status !== AdstoryEpisode::STATUS_SHOTS_GENERATING) {
            return;
        }

        $total = $scenes->count();
        if ($total === 0) {
            return;
        }

        $completed = $scenes->filter(
            fn (AdstoryScene $scene) => in_array(
                $scene->shot_generation_status,
                [AdstoryShotGenerationService::SHOT_STATUS_COMPLETED, null],
                true
            ) && $scene->shots->isNotEmpty()
        )->count();

        $failed = $scenes->where('shot_generation_status', AdstoryShotGenerationService::SHOT_STATUS_FAILED)->count();

        if (($completed + $failed) < $total) {
            return;
        }

        $hasActive = AdstoryAiTask::query()
            ->where('adstory_project_id', $episode->adstory_project_id)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE)
            ->whereIn('taskable_id', $scenes->pluck('id'))
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActive) {
            return;
        }

        $episode->update([
            'status' => $failed > 0 ? AdstoryEpisode::STATUS_FAILED : AdstoryEpisode::STATUS_SHOTS_COMPLETED,
            'shot_generation_status' => $failed > 0 ? 'failed' : 'completed',
            'shot_generation_error' => $failed > 0 ? 'One or more scenes failed shot generation.' : null,
        ]);
    }

    private function assertEpisodeBelongsToProject(AdstoryEpisode $episode, AdstoryProject $project): void
    {
        if ($episode->adstory_project_id !== $project->id) {
            throw new RuntimeException('Episode not found for this project.');
        }
    }

    private function assertEpisodeScenesCompleted(AdstoryEpisode $episode): void
    {
        $incomplete = $episode->scenes()
            ->where('status', '!=', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
            ->exists();

        if ($incomplete || ! $episode->scenes()->exists()) {
            throw new RuntimeException('All episode scenes must be completed before generating shots.');
        }
    }

    private function episodeHasShots(AdstoryEpisode $episode): bool
    {
        return AdstoryShot::query()
            ->whereIn('adstory_scene_id', $episode->scenes()->pluck('id'))
            ->exists();
    }

    private function hasActiveEpisodeShotTasks(AdstoryProject $project, AdstoryEpisode $episode): bool
    {
        $sceneIds = $episode->scenes()->pluck('id');

        if ($sceneIds->isEmpty()) {
            return false;
        }

        return AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE)
            ->whereIn('taskable_id', $sceneIds)
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();
    }
}
