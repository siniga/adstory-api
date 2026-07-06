<?php

namespace App\Services\Adstory;

use App\Models\AdstoryAiTask;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdstoryShotGenerationService
{
    public const SHOT_STATUS_QUEUED = 'queued';

    public const SHOT_STATUS_GENERATING = 'generating';

    public const SHOT_STATUS_COMPLETED = 'completed';

    public const SHOT_STATUS_FAILED = 'failed';

    public const PROJECT_STATUS_IDLE = 'idle';

    public const PROJECT_STATUS_RUNNING = 'running';

    public const PROJECT_STATUS_COMPLETED = 'completed';

    public const PROJECT_STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public function __construct(
        private readonly AdstoryAiTaskService $aiTaskService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startGeneration(AdstoryProject $project, ?string $style = null): array
    {
        $this->assertAllScenesCompleted($project);

        $this->aiTaskService->resetStaleRunningTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE
        );

        $created = $this->ensureMissingShotTasks($project, $style);

        if ($created === 0) {
            Log::info('Adstory shot-generation: no new tasks needed', [
                'project_id' => $project->id,
                'scenes_with_shots' => $this->countScenesWithShots($project),
                'total_scenes' => $this->countCompletedScenes($project),
            ]);

            return array_merge(
                $this->buildProgressPayload($project->fresh()),
                ['started' => false],
            );
        }

        $totalScenes = $this->countCompletedScenes($project);

        $project->update([
            'shot_generation_status' => self::PROJECT_STATUS_RUNNING,
            'shot_generation_total' => $totalScenes,
            'shot_generation_failed' => 0,
            'shot_generation_started_at' => $project->shot_generation_started_at ?? now(),
            'shot_generation_finished_at' => null,
            'current_step' => 'shots',
        ]);

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory shot-generation: tasks created and worker dispatched', [
            'project_id' => $project->id,
            'tasks_created' => $created,
            'total_scenes' => $totalScenes,
        ]);

        return array_merge(
            $this->buildProgressPayload($project->fresh()),
            ['started' => true, 'tasks_created' => $created],
        );
    }

    /**
     * Resume shot generation for scenes that are missing shots or stalled tasks.
     *
     * @return array<string, mixed>
     */
    public function resumeGeneration(AdstoryProject $project, bool $retryFailed = false, ?string $style = null): array
    {
        $this->assertAllScenesCompleted($project);

        $staleReset = $this->aiTaskService->resetStaleRunningTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE
        );

        if ($staleReset > 0) {
            Log::warning('Adstory shot-generation: stale tasks reset during resume', [
                'project_id' => $project->id,
                'count' => $staleReset,
            ]);
        }

        $this->resetStuckGeneratingScenes($project);

        if ($retryFailed) {
            $this->aiTaskService->resetFailedShotGenerationTasks($project->id);
        }

        $created = $this->ensureMissingShotTasks($project, $style);

        if ($created > 0 || $staleReset > 0 || $retryFailed) {
            $project->update([
                'shot_generation_status' => self::PROJECT_STATUS_RUNNING,
                'shot_generation_total' => $this->countCompletedScenes($project),
                'shot_generation_finished_at' => null,
                'current_step' => 'shots',
            ]);

            $this->aiTaskService->dispatchWorker();
        }

        Log::info('Adstory shot-generation: resume dispatched', [
            'project_id' => $project->id,
            'retry_failed' => $retryFailed,
            'tasks_created' => $created,
            'stale_reset' => $staleReset,
        ]);

        return array_merge(
            $this->buildProgressPayload($project->fresh()),
            [
                'resumed' => true,
                'tasks_created' => $created,
                'stale_reset' => $staleReset,
            ],
        );
    }

    /**
     * Queue shot generation tasks for every completed scene that still has no shots.
     */
    public function ensureMissingShotTasks(AdstoryProject $project, ?string $style = null): int
    {
        $style = $style ?? $project->visual_style;
        $created = 0;

        $scenes = $project->scenes()
            ->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
            ->withCount('shots')
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($project, $scenes, $style, &$created) {
            foreach ($scenes as $index => $scene) {
                if ($this->sceneHasCompletedShots($scene)) {
                    continue;
                }

                if ($this->hasActiveShotGenerationTask($project, $scene)) {
                    continue;
                }

                Log::info("Scene status before shot generation: {$scene->status}", [
                    'scene_id' => $scene->id,
                    'scene_number' => $scene->scene_number,
                    'project_id' => $project->id,
                    'shots_count' => $scene->shots_count ?? 0,
                ]);

                $scene->markShotGenerationQueued();

                $this->aiTaskService->createTask(
                    project: $project,
                    type: AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE,
                    taskable: $scene,
                    payload: [
                        'project_id' => $project->id,
                        'scene_id' => $scene->id,
                        'scene_number' => $scene->scene_number,
                        'style' => $style,
                        'source' => 'project_shot_generation',
                    ],
                    priority: 9000 - $index,
                );

                $created++;
            }
        });

        if ($created > 0) {
            $project->update([
                'shot_generation_total' => $scenes->count(),
            ]);
        }

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProgress(AdstoryProject $project): array
    {
        return $this->buildProgressPayload($project);
    }

    public function finalizeProjectFromShots(AdstoryProject $project): void
    {
        $project->load(['scenes' => fn ($query) => $query->orderBy('order_index')->orderBy('id')]);

        $scenes = $project->scenes
            ->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
            ->values();

        $this->finalizeProjectIfDone($project, $scenes);
    }

    private function assertAllScenesCompleted(AdstoryProject $project): void
    {
        $scenes = $project->scenes()->orderBy('order_index')->orderBy('id')->get();

        if ($scenes->isEmpty()) {
            throw new RuntimeException('Project must have scenes before starting shot generation.');
        }

        $incomplete = $scenes->filter(
            fn (AdstoryScene $scene) => $scene->status !== AdstorySceneGenerationService::SCENE_STATUS_COMPLETED
        );

        if ($incomplete->isNotEmpty()) {
            $numbers = $incomplete
                ->map(fn (AdstoryScene $scene) => $scene->scene_number ?? $scene->id)
                ->values()
                ->all();

            throw new RuntimeException(
                'All scenes must be completed before starting shot generation. Incomplete scenes: '
                .implode(', ', $numbers)
            );
        }
    }

    private function sceneHasCompletedShots(AdstoryScene $scene): bool
    {
        if (($scene->shots_count ?? 0) > 0) {
            return true;
        }

        $status = $this->resolveShotGenerationStatus($scene);

        return $status === self::SHOT_STATUS_COMPLETED;
    }

    private function hasActiveShotGenerationTask(AdstoryProject $project, AdstoryScene $scene): bool
    {
        return AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE)
            ->where('taskable_id', $scene->id)
            ->whereIn('status', [
                AdstoryAiTask::STATUS_QUEUED,
                AdstoryAiTask::STATUS_RUNNING,
            ])
            ->exists();
    }

    private function countCompletedScenes(AdstoryProject $project): int
    {
        return $project->scenes()
            ->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
            ->count();
    }

    private function countScenesWithShots(AdstoryProject $project): int
    {
        return $project->scenes()
            ->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
            ->whereHas('shots')
            ->count();
    }

    private function resetStuckGeneratingScenes(AdstoryProject $project): void
    {
        $scenes = $project->scenes()
            ->where('shot_generation_status', self::SHOT_STATUS_GENERATING)
            ->get();

        foreach ($scenes as $scene) {
            if (! $this->hasActiveShotGenerationTask($project, $scene)) {
                $scene->markShotGenerationQueued();

                Log::warning('Adstory shot-generation: reset stuck generating scene to queued', [
                    'project_id' => $project->id,
                    'scene_id' => $scene->id,
                    'scene_number' => $scene->scene_number,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProgressPayload(AdstoryProject $project): array
    {
        $this->aiTaskService->resetStaleRunningTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE
        );

        $project->refresh()->load([
            'scenes' => fn ($query) => $query->withCount('shots')->orderBy('order_index')->orderBy('id'),
            'shots.scene',
        ]);

        $scenes = $project->scenes
            ->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
            ->sortBy(fn (AdstoryScene $scene) => [$scene->order_index, $scene->id])
            ->values();

        $total = $scenes->count();
        $completed = $this->countScenesByShotStatus($scenes, self::SHOT_STATUS_COMPLETED);
        $failed = $this->countScenesByShotStatus($scenes, self::SHOT_STATUS_FAILED);
        $running = $this->countScenesByShotStatus($scenes, self::SHOT_STATUS_GENERATING);
        $queued = $this->countScenesByShotStatus($scenes, self::SHOT_STATUS_QUEUED);
        $remaining = $scenes->filter(
            fn (AdstoryScene $scene) => ! in_array(
                $this->resolveShotGenerationStatus($scene),
                [self::SHOT_STATUS_COMPLETED, self::SHOT_STATUS_FAILED],
                true
            )
        )->count();

        $progressPercent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        $taskCounts = $this->aiTaskService->getTaskCounts(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE
        );

        if ($running === 0 && $taskCounts['running'] > 0) {
            $running = $taskCounts['running'];
        }

        $this->finalizeProjectIfDone($project, $scenes);
        $project->refresh();

        $project->update([
            'shot_generation_completed' => $completed,
            'shot_generation_failed' => $failed,
            'shot_generation_total' => $total,
        ]);

        $currentScene = $scenes->first(
            fn (AdstoryScene $scene) => $this->resolveShotGenerationStatus($scene) === self::SHOT_STATUS_GENERATING
        ) ?? $scenes->first(
            fn (AdstoryScene $scene) => $this->resolveShotGenerationStatus($scene) === self::SHOT_STATUS_QUEUED
        );

        $estimatedRemaining = $this->estimateRemainingSeconds($project->id, $remaining);

        $shots = $project->shots
            ->sortBy(fn (AdstoryShot $shot) => [$shot->order_index, $shot->id])
            ->map(fn (AdstoryShot $shot) => $shot->toApiArray())
            ->values()
            ->all();

        Log::info('Adstory shot-generation: progress calculated', [
            'project_id' => $project->id,
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'queued' => $queued,
            'remaining' => $remaining,
        ]);

        $status = $project->shot_generation_status ?? self::PROJECT_STATUS_IDLE;

        if (
            $status === self::PROJECT_STATUS_IDLE
            && $total > 0
            && $completed === $total
            && $project->shots()->exists()
        ) {
            $status = self::PROJECT_STATUS_COMPLETED;
        }

        return [
            'success' => true,
            'project_id' => $project->id,
            'status' => $status,
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'queued' => $queued,
            'remaining' => $remaining,
            'progress_percent' => $progressPercent,
            'estimated_remaining' => $estimatedRemaining,
            'current_scene' => $currentScene ? $this->toShotProgressScene($currentScene) : null,
            'project' => $project->toApiArray(),
            'shots' => $shots,
            'tasks' => [
                'queued' => $taskCounts['queued'],
                'running' => $taskCounts['running'],
                'completed' => $taskCounts['completed'],
                'failed' => $taskCounts['failed'],
            ],
        ];
    }

    /**
     * @param  Collection<int, AdstoryScene>  $scenes
     */
    private function finalizeProjectIfDone(AdstoryProject $project, Collection $scenes): void
    {
        if ($project->shot_generation_status !== self::PROJECT_STATUS_RUNNING) {
            return;
        }

        $total = $scenes->count();

        if ($total <= 0) {
            return;
        }

        $completed = $this->countScenesByShotStatus($scenes, self::SHOT_STATUS_COMPLETED);
        $failed = $this->countScenesByShotStatus($scenes, self::SHOT_STATUS_FAILED);
        $processed = $completed + $failed;

        if ($processed < $total) {
            return;
        }

        $hasIncomplete = $scenes->contains(
            fn (AdstoryScene $scene) => ! in_array(
                $this->resolveShotGenerationStatus($scene),
                [self::SHOT_STATUS_COMPLETED, self::SHOT_STATUS_FAILED],
                true
            )
        );

        if ($hasIncomplete) {
            return;
        }

        $hasActiveTasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE)
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActiveTasks) {
            return;
        }

        $status = $failed > 0
            ? self::PROJECT_STATUS_COMPLETED_WITH_ERRORS
            : self::PROJECT_STATUS_COMPLETED;

        $project->update([
            'shot_generation_status' => $status,
            'shot_generation_total' => $total,
            'shot_generation_completed' => $completed,
            'shot_generation_failed' => $failed,
            'shot_generation_finished_at' => now(),
        ]);

        Log::info('Adstory shot-generation: batch finished', [
            'project_id' => $project->id,
            'status' => $status,
            'completed' => $completed,
            'failed' => $failed,
            'total' => $total,
        ]);
    }

    /**
     * @param  Collection<int, AdstoryScene>  $scenes
     */
    private function countScenesByShotStatus(Collection $scenes, string $status): int
    {
        return $scenes
            ->filter(fn (AdstoryScene $scene) => $this->resolveShotGenerationStatus($scene) === $status)
            ->count();
    }

    private function resolveShotGenerationStatus(AdstoryScene $scene): ?string
    {
        if ($scene->shot_generation_status !== null) {
            return $scene->shot_generation_status;
        }

        $meta = is_array($scene->meta ?? null) ? $scene->meta : [];

        if (isset($meta['shot_generation_status'])) {
            return $meta['shot_generation_status'];
        }

        if (($scene->shots_count ?? 0) > 0) {
            return self::SHOT_STATUS_COMPLETED;
        }

        return null;
    }

    private function estimateRemainingSeconds(int $projectId, int $remaining): ?int
    {
        if ($remaining <= 0) {
            return 0;
        }

        $durations = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE)
            ->where('status', AdstoryAiTask::STATUS_COMPLETED)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get()
            ->map(fn (AdstoryAiTask $task) => $task->completed_at->diffInSeconds($task->started_at))
            ->filter(fn (int $seconds) => $seconds > 0);

        if ($durations->isEmpty()) {
            return null;
        }

        return (int) round($durations->avg()) * $remaining;
    }

    /**
     * @return array<string, mixed>
     */
    private function toShotProgressScene(AdstoryScene $scene): array
    {
        return [
            'id' => $scene->id,
            'scene_number' => $scene->scene_number,
            'title' => $scene->title,
            'status' => $scene->status,
            'shot_generation_status' => $this->resolveShotGenerationStatus($scene),
        ];
    }
}
