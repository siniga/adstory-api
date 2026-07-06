<?php

namespace App\Services\Adstory;

use App\Jobs\Adstory\ProcessAdstoryAiTaskJob;
use App\Models\AdstoryAiTask;
use App\Models\AdstoryCharacter;
use App\Models\AdstoryEnvironment;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AdstoryAiTaskService
{
    public const TASK_LOCK_TIMEOUT_MINUTES = 5;

    private ?AdstoryAiTaskProcessor $taskProcessor = null;

    private function taskProcessor(): AdstoryAiTaskProcessor
    {
        return $this->taskProcessor ??= app(AdstoryAiTaskProcessor::class);
    }

    public function dispatchWorker(): void
    {
        ProcessAdstoryAiTaskJob::dispatch();
    }

    public function hasQueuedTasks(?int $projectId = null): bool
    {
        $query = AdstoryAiTask::query()->where('status', AdstoryAiTask::STATUS_QUEUED);

        if ($projectId !== null) {
            $query->where('adstory_project_id', $projectId);
        }

        return $query->exists();
    }

    public function hasEligibleQueuedTasks(?int $projectId = null, ?string $type = null): bool
    {
        return $this->countEligibleQueuedTasks($projectId, $type) > 0;
    }

    public function countEligibleQueuedTasks(?int $projectId = null, ?string $type = null): int
    {
        $query = AdstoryAiTask::query()
            ->where('status', AdstoryAiTask::STATUS_QUEUED)
            ->where(function ($eligibleQuery) {
                $eligibleQuery
                    ->whereNotIn('type', AdstoryAiTask::SCENE_BLOCKED_TYPES)
                    ->orWhereHas('project', function ($projectQuery) {
                        $projectQuery->whereNotIn(
                            'scene_generation_status',
                            AdstorySceneGenerationService::BLOCKED_WORKER_STATUSES
                        );
                    });
            });

        if ($projectId !== null) {
            $query->where('adstory_project_id', $projectId);
        }

        if ($type !== null) {
            $query->where('type', $type);
        }

        return (int) $query->count();
    }

    public function countRunningTasks(?int $projectId = null, ?string $type = null): int
    {
        $query = AdstoryAiTask::query()->where('status', AdstoryAiTask::STATUS_RUNNING);

        if ($projectId !== null) {
            $query->where('adstory_project_id', $projectId);
        }

        if ($type !== null) {
            $query->where('type', $type);
        }

        return (int) $query->count();
    }

    public function hasStaleRunningTasks(?int $projectId = null, ?string $type = null): bool
    {
        $query = AdstoryAiTask::query()
            ->where('status', AdstoryAiTask::STATUS_RUNNING)
            ->where('locked_at', '<', now()->subMinutes(self::TASK_LOCK_TIMEOUT_MINUTES));

        if ($projectId !== null) {
            $query->where('adstory_project_id', $projectId);
        }

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->exists();
    }

    public function processNextTask(): bool
    {
        $this->resetStaleRunningTasks();

        $task = $this->acquireNextTask();

        if (! $task) {
            return false;
        }

        if ($this->stopTaskIfProjectMissing($task)) {
            return true;
        }

        Log::info('Adstory AI task: started', [
            'task_id' => $task->id,
            'type' => $task->type,
            'project_id' => $task->adstory_project_id,
            'attempt' => $task->attempt_count,
        ]);

        try {
            $result = $this->processTask($task);

            if (! empty($result['failed'])) {
                $task->update([
                    'status' => AdstoryAiTask::STATUS_FAILED,
                    'result' => $result,
                    'error' => (string) ($result['error'] ?? 'Task failed.'),
                    'failed_at' => now(),
                    'locked_at' => null,
                    'locked_by' => null,
                ]);

                Log::error('Adstory AI task: failed (handled result)', [
                    'task_id' => $task->id,
                    'type' => $task->type,
                    'project_id' => $task->adstory_project_id,
                    'message' => $result['error'] ?? null,
                ]);
            } else {
                $this->markTaskCompleted($task, $result);

                Log::info('Adstory AI task: completed', [
                    'task_id' => $task->id,
                    'type' => $task->type,
                    'project_id' => $task->adstory_project_id,
                ]);
            }
        } catch (Throwable $e) {
            if ($this->isDeletedProjectError($e)) {
                $this->stopTaskIfProjectMissing($task);

                return true;
            }

            $this->markTaskFailed($task, $e->getMessage());

            Log::error('Adstory AI task: failed', [
                'task_id' => $task->id,
                'type' => $task->type,
                'project_id' => $task->adstory_project_id,
                'attempt' => $task->attempt_count,
                'message' => $e->getMessage(),
            ]);
        } finally {
            $this->releaseTaskLock($task->fresh());
            $this->maybeFinalizeProject($task->adstory_project_id);
        }

        return true;
    }

    public function maybeFinalizeProject(int $projectId): void
    {
        $this->maybeFinalizeSceneGeneration($projectId);
        $this->maybeFinalizeShotGeneration($projectId);
        $this->maybeFinalizeCharacterGeneration($projectId);
        $this->maybeFinalizeEnvironmentGeneration($projectId);
        $this->maybeFinalizeEpisodeShots($projectId);
    }

    private function maybeFinalizeSceneGeneration(int $projectId): void
    {
        $hasActiveTasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SCENE)
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActiveTasks) {
            return;
        }

        $project = AdstoryProject::query()->with('scenes')->find($projectId);

        if (! $project) {
            return;
        }

        if (in_array($project->scene_generation_status, AdstorySceneGenerationService::BLOCKED_WORKER_STATUSES, true)) {
            return;
        }

        $sceneGenerationService = app(AdstorySceneGenerationService::class);
        $sceneGenerationService->syncSceneStatusesFromTasks($project);
        $sceneGenerationService->finalizeProjectFromScenes($project->fresh());
    }

    private function maybeFinalizeShotGeneration(int $projectId): void
    {
        $hasActiveTasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE)
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActiveTasks) {
            return;
        }

        $project = AdstoryProject::query()->find($projectId);

        if (! $project) {
            return;
        }

        app(AdstoryShotGenerationService::class)->finalizeProjectFromShots($project);
    }

    private function maybeFinalizeCharacterGeneration(int $projectId): void
    {
        $hasActiveTasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->whereIn('type', [
                AdstoryAiTask::TYPE_EXTRACT_CHARACTERS,
                AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE,
            ])
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActiveTasks) {
            return;
        }

        $project = AdstoryProject::query()->find($projectId);

        if (! $project) {
            return;
        }

        app(AdstoryCharacterGenerationService::class)->finalizeProjectFromCharacters($project);
    }

    private function maybeFinalizeEnvironmentGeneration(int $projectId): void
    {
        $hasActiveTasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->whereIn('type', [
                AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS,
                AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE,
            ])
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActiveTasks) {
            return;
        }

        $project = AdstoryProject::query()->find($projectId);

        if (! $project) {
            return;
        }

        app(AdstoryEnvironmentGenerationService::class)->finalizeProjectFromEnvironments($project);
    }

    private function maybeFinalizeEpisodeShots(int $projectId): void
    {
        $episodes = \App\Models\AdstoryEpisode::query()
            ->where('adstory_project_id', $projectId)
            ->where('status', \App\Models\AdstoryEpisode::STATUS_SHOTS_GENERATING)
            ->get();

        if ($episodes->isEmpty()) {
            return;
        }

        $service = app(AdstoryEpisodeShotGenerationService::class);

        foreach ($episodes as $episode) {
            $episode->load(['scenes.shots']);
            $service->finalizeEpisodeIfDone($episode, $episode->scenes);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createTask(
        AdstoryProject $project,
        string $type,
        ?Model $taskable,
        array $payload = [],
        int $priority = 0,
        int $maxAttempts = 3,
    ): AdstoryAiTask {
        $task = AdstoryAiTask::query()->create([
            'adstory_project_id' => $project->id,
            'taskable_type' => $taskable ? $taskable::class : null,
            'taskable_id' => $taskable?->getKey(),
            'type' => $type,
            'status' => AdstoryAiTask::STATUS_QUEUED,
            'priority' => $priority,
            'attempt_count' => 0,
            'max_attempts' => $maxAttempts,
            'payload' => $payload === [] ? null : $payload,
        ]);

        Log::info('Adstory AI task: created', [
            'task_id' => $task->id,
            'type' => $type,
            'project_id' => $project->id,
            'taskable_type' => $task->taskable_type,
            'taskable_id' => $task->taskable_id,
        ]);

        return $task;
    }

    public function deleteTasksByType(int $projectId, string $type): void
    {
        AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', $type)
            ->delete();
    }

    public function resetFailedTasks(int $projectId, string $type): int
    {
        $tasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', $type)
            ->where('status', AdstoryAiTask::STATUS_FAILED)
            ->get();

        foreach ($tasks as $task) {
            $task->update([
                'status' => AdstoryAiTask::STATUS_QUEUED,
                'attempt_count' => 0,
                'error' => null,
                'failed_at' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            $this->resetRelatedModelForRetry($task);

            Log::info('Adstory AI task: retried', [
                'task_id' => $task->id,
                'project_id' => $projectId,
                'type' => $type,
            ]);
        }

        return $tasks->count();
    }

    /**
     * @param  array<string, mixed>  $planItem
     */
    public function createGenerateSceneTask(
        AdstoryProject $project,
        AdstoryScene $scene,
        array $planItem,
        int $orderIndex,
    ): AdstoryAiTask {
        return $this->createTask(
            project: $project,
            type: AdstoryAiTask::TYPE_GENERATE_SCENE,
            taskable: $scene,
            payload: $planItem,
            priority: 10000 - $orderIndex,
        );
    }

    public function cancelQueuedSceneTasks(int $projectId): int
    {
        $count = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SCENE)
            ->where('status', AdstoryAiTask::STATUS_QUEUED)
            ->update([
                'status' => AdstoryAiTask::STATUS_CANCELLED,
                'locked_at' => null,
                'locked_by' => null,
            ]);

        Log::info('Adstory AI task: queued tasks cancelled', [
            'project_id' => $projectId,
            'count' => $count,
        ]);

        return $count;
    }

    public function deleteSceneTasks(int $projectId): void
    {
        AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SCENE)
            ->delete();
    }

    public function resetIncompleteSceneTasks(int $projectId): int
    {
        $tasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SCENE)
            ->whereIn('status', [
                AdstoryAiTask::STATUS_FAILED,
                AdstoryAiTask::STATUS_CANCELLED,
            ])
            ->get();

        foreach ($tasks as $task) {
            $task->update([
                'status' => AdstoryAiTask::STATUS_QUEUED,
                'attempt_count' => 0,
                'error' => null,
                'result' => null,
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            Log::info('Adstory AI task: retried', [
                'task_id' => $task->id,
                'project_id' => $projectId,
            ]);
        }

        return $tasks->count();
    }

    public function resetCancelledSceneTasks(int $projectId): int
    {
        $tasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SCENE)
            ->where('status', AdstoryAiTask::STATUS_CANCELLED)
            ->get();

        foreach ($tasks as $task) {
            $task->update([
                'status' => AdstoryAiTask::STATUS_QUEUED,
                'attempt_count' => 0,
                'error' => null,
                'failed_at' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);
        }

        return $tasks->count();
    }

    public function cancelIncompleteSceneTasks(int $projectId): void
    {
        $this->cancelAllActiveTasks($projectId);
    }

    public function cancelAllActiveTasks(int $projectId): int
    {
        $count = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->whereIn('status', [
                AdstoryAiTask::STATUS_QUEUED,
                AdstoryAiTask::STATUS_RUNNING,
            ])
            ->update([
                'status' => AdstoryAiTask::STATUS_CANCELLED,
                'error' => 'Project deleted.',
                'locked_at' => null,
                'locked_by' => null,
            ]);

        if ($count > 0) {
            Log::info('Adstory AI task: active tasks cancelled', [
                'project_id' => $projectId,
                'count' => $count,
            ]);
        }

        return $count;
    }

    /**
     * Cancel queued/running storyboard image tasks for shots in a scene.
     */
    public function cancelStoryboardImageTasksForScene(int $projectId, int $sceneId): int
    {
        $shotIds = AdstoryShot::query()
            ->where('adstory_project_id', $projectId)
            ->where('adstory_scene_id', $sceneId)
            ->pluck('id');

        if ($shotIds->isEmpty()) {
            return 0;
        }

        $count = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_STORYBOARD_IMAGE_FOR_SHOT)
            ->whereIn('taskable_id', $shotIds)
            ->whereIn('status', [
                AdstoryAiTask::STATUS_QUEUED,
                AdstoryAiTask::STATUS_RUNNING,
            ])
            ->update([
                'status' => AdstoryAiTask::STATUS_CANCELLED,
                'error' => 'Cancelled by user.',
                'locked_at' => null,
                'locked_by' => null,
            ]);

        if ($count > 0) {
            Log::info('Adstory AI task: storyboard image tasks cancelled for scene', [
                'project_id' => $projectId,
                'scene_id' => $sceneId,
                'count' => $count,
            ]);
        }

        return $count;
    }

    /**
     * Cancel queued/running shot text generation task for a scene.
     */
    public function cancelShotGenerationTaskForScene(int $projectId, int $sceneId): int
    {
        $count = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE)
            ->where('taskable_id', $sceneId)
            ->whereIn('status', [
                AdstoryAiTask::STATUS_QUEUED,
                AdstoryAiTask::STATUS_RUNNING,
            ])
            ->update([
                'status' => AdstoryAiTask::STATUS_CANCELLED,
                'error' => 'Cancelled by user.',
                'locked_at' => null,
                'locked_by' => null,
            ]);

        if ($count > 0) {
            Log::info('Adstory AI task: shot generation task cancelled for scene', [
                'project_id' => $projectId,
                'scene_id' => $sceneId,
                'count' => $count,
            ]);
        }

        return $count;
    }

    public function stopTaskIfProjectMissing(AdstoryAiTask $task): bool
    {
        if (AdstoryProject::query()->where('id', $task->adstory_project_id)->exists()) {
            return false;
        }

        if (AdstoryAiTask::query()->where('id', $task->id)->exists()) {
            $task->update([
                'status' => AdstoryAiTask::STATUS_CANCELLED,
                'error' => 'Project no longer exists.',
                'locked_at' => null,
                'locked_by' => null,
            ]);
        }

        Log::info('Adstory AI task: stopped because project was deleted', [
            'task_id' => $task->id,
            'project_id' => $task->adstory_project_id,
        ]);

        return true;
    }

    public function resetOrCreateSceneTask(AdstoryProject $project, AdstoryScene $scene): AdstoryAiTask
    {
        $payload = is_array($scene->meta['generation_plan'] ?? null)
            ? $scene->meta['generation_plan']
            : [
                'scene_number' => $scene->scene_number,
                'title' => $scene->title,
                'location' => $scene->location,
                'time_of_day' => $scene->time_of_day,
                'summary' => ($scene->meta ?? [])['summary'] ?? $scene->description,
            ];

        $task = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SCENE)
            ->where('taskable_type', AdstoryScene::class)
            ->where('taskable_id', $scene->id)
            ->orderByDesc('id')
            ->first();

        if ($task) {
            $task->update([
                'status' => AdstoryAiTask::STATUS_QUEUED,
                'attempt_count' => 0,
                'error' => null,
                'result' => null,
                'started_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'locked_at' => null,
                'locked_by' => null,
                'payload' => $payload,
            ]);

            Log::info('Adstory AI task: retried', [
                'task_id' => $task->id,
                'scene_id' => $scene->id,
                'project_id' => $project->id,
            ]);

            return $task->fresh();
        }

        return $this->createGenerateSceneTask(
            project: $project,
            scene: $scene,
            planItem: $payload,
            orderIndex: (int) $scene->order_index,
        );
    }

    public function resetStaleRunningTasks(?int $projectId = null, ?string $type = null): int
    {
        $query = AdstoryAiTask::query()
            ->where('status', AdstoryAiTask::STATUS_RUNNING)
            ->where('locked_at', '<', now()->subMinutes(self::TASK_LOCK_TIMEOUT_MINUTES));

        if ($projectId !== null) {
            $query->where('adstory_project_id', $projectId);
        }

        if ($type !== null) {
            $query->where('type', $type);
        }

        $tasks = $query->get();
        $count = 0;

        foreach ($tasks as $task) {
            $task->update([
                'status' => AdstoryAiTask::STATUS_QUEUED,
                'locked_at' => null,
                'locked_by' => null,
                'error' => 'Task lock expired. Requeued for retry.',
            ]);

            if (
                $task->type === AdstoryAiTask::TYPE_GENERATE_SCENE
                && $task->taskable_type === AdstoryScene::class
                && $task->taskable_id
            ) {
                AdstoryScene::query()
                    ->where('id', $task->taskable_id)
                    ->where('status', AdstorySceneGenerationService::SCENE_STATUS_GENERATING)
                    ->update([
                        'status' => AdstorySceneGenerationService::SCENE_STATUS_QUEUED,
                        'generation_error' => null,
                    ]);
            }

            if ($task->type === AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE && $task->taskable_id) {
                $scene = AdstoryScene::query()->find($task->taskable_id);

                if ($scene && $scene->shot_generation_status === AdstoryShotGenerationService::SHOT_STATUS_GENERATING) {
                    $scene->markShotGenerationQueued();
                }
            }

            if ($task->type === AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE && $task->taskable_id) {
                $character = AdstoryCharacter::query()->find($task->taskable_id);

                if ($character && $character->image_status !== AdstoryCharacterGenerationService::IMAGE_STATUS_COMPLETED) {
                    $character->markImageGenerationQueued();
                    $character->update(['generation_error' => null]);
                }
            }

            if ($task->type === AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE && $task->taskable_id) {
                $environment = AdstoryEnvironment::query()->find($task->taskable_id);

                if ($environment && $environment->image_status !== AdstoryEnvironmentGenerationService::IMAGE_STATUS_COMPLETED) {
                    $environment->markImageGenerationQueued();
                    $environment->update(['generation_error' => null]);
                }
            }

            if ($task->type === AdstoryAiTask::TYPE_GENERATE_STORYBOARD_IMAGE_FOR_SHOT && $task->taskable_id) {
                $shot = AdstoryShot::query()->find($task->taskable_id);

                if ($shot && $shot->image_status === AdstoryStoryboardService::IMAGE_STATUS_GENERATING) {
                    $shot->markStoryboardImageQueued();
                }
            }

            if ($task->type === AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE && $task->taskable_id) {
                $scene = AdstoryScene::query()->find($task->taskable_id);

                if ($scene && $scene->shot_generation_status === AdstoryShotGenerationService::SHOT_STATUS_GENERATING) {
                    $scene->markShotGenerationQueued();
                }
            }

            $count++;

            Log::warning('Adstory AI task: stale task detected and requeued', [
                'task_id' => $task->id,
                'project_id' => $task->adstory_project_id,
                'type' => $task->type,
            ]);
        }

        if ($count > 0) {
            Log::warning('Adstory AI task: stale running tasks reset', [
                'count' => $count,
                'project_id' => $projectId,
                'type' => $type,
            ]);
        }

        return $count;
    }

    public function resetFailedCharacterImageTasks(int $projectId): int
    {
        $tasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE)
            ->where('status', AdstoryAiTask::STATUS_FAILED)
            ->get();

        foreach ($tasks as $task) {
            $task->update([
                'status' => AdstoryAiTask::STATUS_QUEUED,
                'attempt_count' => 0,
                'error' => null,
                'failed_at' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            if ($task->taskable_id) {
                $character = AdstoryCharacter::query()->find($task->taskable_id);

                if ($character && $character->image_status !== AdstoryCharacterGenerationService::IMAGE_STATUS_COMPLETED) {
                    $character->markImageGenerationQueued();
                    $character->update(['generation_error' => null]);
                }
            }

            Log::info('Adstory AI task: failed character image task requeued', [
                'task_id' => $task->id,
                'project_id' => $projectId,
            ]);
        }

        return $tasks->count();
    }

    public function resetFailedEnvironmentImageTasks(int $projectId): int
    {
        $tasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE)
            ->where('status', AdstoryAiTask::STATUS_FAILED)
            ->get();

        foreach ($tasks as $task) {
            $task->update([
                'status' => AdstoryAiTask::STATUS_QUEUED,
                'attempt_count' => 0,
                'error' => null,
                'failed_at' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            if ($task->taskable_id) {
                $environment = AdstoryEnvironment::query()->find($task->taskable_id);

                if ($environment && $environment->image_status !== AdstoryEnvironmentGenerationService::IMAGE_STATUS_COMPLETED) {
                    $environment->markImageGenerationQueued();
                    $environment->update(['generation_error' => null]);
                }
            }

            Log::info('Adstory AI task: failed environment image task requeued', [
                'task_id' => $task->id,
                'project_id' => $projectId,
            ]);
        }

        return $tasks->count();
    }

    /**
     * Requeue failed storyboard image tasks for shots in a specific scene.
     */
    public function resetFailedStoryboardImageTasksForScene(int $projectId, int $sceneId): int
    {
        $shotIds = AdstoryShot::query()
            ->where('adstory_project_id', $projectId)
            ->where('adstory_scene_id', $sceneId)
            ->pluck('id');

        if ($shotIds->isEmpty()) {
            return 0;
        }

        $tasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_STORYBOARD_IMAGE_FOR_SHOT)
            ->whereIn('taskable_id', $shotIds)
            ->where('status', AdstoryAiTask::STATUS_FAILED)
            ->get();

        foreach ($tasks as $task) {
            $task->update([
                'status' => AdstoryAiTask::STATUS_QUEUED,
                'attempt_count' => 0,
                'error' => null,
                'failed_at' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            if ($task->taskable_id) {
                $shot = AdstoryShot::query()->find($task->taskable_id);

                if ($shot && $shot->image_status !== AdstoryStoryboardService::IMAGE_STATUS_COMPLETED) {
                    $shot->markStoryboardImageQueued();
                }
            }

            Log::info('Adstory AI task: failed storyboard image task requeued', [
                'task_id' => $task->id,
                'project_id' => $projectId,
                'scene_id' => $sceneId,
            ]);
        }

        return $tasks->count();
    }

    public function resetFailedShotGenerationTasks(int $projectId): int
    {
        $tasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE)
            ->where('status', AdstoryAiTask::STATUS_FAILED)
            ->get();

        foreach ($tasks as $task) {
            $task->update([
                'status' => AdstoryAiTask::STATUS_QUEUED,
                'attempt_count' => 0,
                'error' => null,
                'failed_at' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            if ($task->taskable_id) {
                $scene = AdstoryScene::query()->find($task->taskable_id);

                if ($scene && ! $scene->shots()->exists()) {
                    $scene->markShotGenerationQueued();
                }
            }

            Log::info('Adstory AI task: failed shot generation task requeued', [
                'task_id' => $task->id,
                'project_id' => $projectId,
                'scene_id' => $task->taskable_id,
            ]);
        }

        return $tasks->count();
    }

    public function resetFailedSceneTasks(int $projectId): int
    {
        $tasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SCENE)
            ->where('status', AdstoryAiTask::STATUS_FAILED)
            ->get();

        foreach ($tasks as $task) {
            $task->update([
                'status' => AdstoryAiTask::STATUS_QUEUED,
                'attempt_count' => 0,
                'error' => null,
                'failed_at' => null,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            if ($task->taskable_id) {
                AdstoryScene::query()
                    ->where('id', $task->taskable_id)
                    ->where('status', AdstorySceneGenerationService::SCENE_STATUS_FAILED)
                    ->update([
                        'status' => AdstorySceneGenerationService::SCENE_STATUS_QUEUED,
                        'generation_error' => null,
                        'generated_at' => null,
                    ]);
            }

            Log::info('Adstory AI task: retried', [
                'task_id' => $task->id,
                'project_id' => $projectId,
            ]);
        }

        return $tasks->count();
    }

    /**
     * @return array<string, int>
     */
    public function getTaskCounts(int $projectId, string $type = AdstoryAiTask::TYPE_GENERATE_SCENE): array
    {
        $counts = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', $type)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'queued' => (int) ($counts[AdstoryAiTask::STATUS_QUEUED] ?? 0),
            'running' => (int) ($counts[AdstoryAiTask::STATUS_RUNNING] ?? 0),
            'completed' => (int) ($counts[AdstoryAiTask::STATUS_COMPLETED] ?? 0),
            'failed' => (int) ($counts[AdstoryAiTask::STATUS_FAILED] ?? 0),
            'cancelled' => (int) ($counts[AdstoryAiTask::STATUS_CANCELLED] ?? 0),
        ];
    }

    private function acquireNextTask(): ?AdstoryAiTask
    {
        return DB::transaction(function () {
            $task = AdstoryAiTask::query()
                ->where('status', AdstoryAiTask::STATUS_QUEUED)
                ->where(function ($eligibleQuery) {
                    $eligibleQuery
                        ->whereNotIn('type', AdstoryAiTask::SCENE_BLOCKED_TYPES)
                        ->orWhereHas('project', function ($projectQuery) {
                            $projectQuery->whereNotIn(
                                'scene_generation_status',
                                AdstorySceneGenerationService::BLOCKED_WORKER_STATUSES
                            );
                        });
                })
                ->orderByDesc('priority')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $task) {
                return null;
            }

            $project = AdstoryProject::query()->find($task->adstory_project_id);

            if (! $project) {
                return null;
            }

            if (
                in_array($task->type, AdstoryAiTask::SCENE_BLOCKED_TYPES, true)
                && in_array($project->scene_generation_status, AdstorySceneGenerationService::BLOCKED_WORKER_STATUSES, true)
            ) {
                return null;
            }

            $task->update([
                'status' => AdstoryAiTask::STATUS_RUNNING,
                'attempt_count' => $task->attempt_count + 1,
                'started_at' => now(),
                'locked_at' => now(),
                'locked_by' => $this->workerIdentity(),
                'error' => null,
            ]);

            Log::info('Adstory AI task: locked', [
                'task_id' => $task->id,
                'locked_by' => $task->locked_by,
            ]);

            return $task->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function processTask(AdstoryAiTask $task): array
    {
        return $this->taskProcessor()->process($task);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function markTaskCompleted(AdstoryAiTask $task, array $result): void
    {
        $task->update([
            'status' => AdstoryAiTask::STATUS_COMPLETED,
            'result' => $result,
            'completed_at' => now(),
            'error' => null,
        ]);
    }

    private function markTaskFailed(AdstoryAiTask $task, string $message): void
    {
        $task->refresh();

        if ($task->attempt_count < $task->max_attempts) {
            $project = AdstoryProject::query()->find($task->adstory_project_id);

            if (
                $project
                && in_array($task->type, AdstoryAiTask::SCENE_BLOCKED_TYPES, true)
                && in_array($project->scene_generation_status, AdstorySceneGenerationService::BLOCKED_WORKER_STATUSES, true)
            ) {
                $task->update([
                    'status' => AdstoryAiTask::STATUS_CANCELLED,
                    'error' => $message,
                    'locked_at' => null,
                    'locked_by' => null,
                ]);

                return;
            }

            $task->update([
                'status' => AdstoryAiTask::STATUS_QUEUED,
                'error' => $message,
                'locked_at' => null,
                'locked_by' => null,
            ]);

            if ($task->type === AdstoryAiTask::TYPE_GENERATE_SCENE && $task->taskable_id) {
                AdstoryScene::query()
                    ->where('id', $task->taskable_id)
                    ->update([
                        'status' => AdstorySceneGenerationService::SCENE_STATUS_QUEUED,
                        'generation_error' => $message,
                    ]);
            }

            if ($task->type === AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE && $task->taskable_id) {
                $scene = AdstoryScene::query()->find($task->taskable_id);

                if ($scene) {
                    $scene->markShotGenerationQueued();
                }
            }

            if ($task->type === AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE && $task->taskable_id) {
                $character = AdstoryCharacter::query()->find($task->taskable_id);

                if ($character && $character->image_status !== AdstoryCharacterGenerationService::IMAGE_STATUS_COMPLETED) {
                    $character->markImageGenerationQueued();
                    $character->update(['generation_error' => null]);
                }
            }

            if ($task->type === AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE && $task->taskable_id) {
                $environment = AdstoryEnvironment::query()->find($task->taskable_id);

                if ($environment && $environment->image_status !== AdstoryEnvironmentGenerationService::IMAGE_STATUS_COMPLETED) {
                    $environment->markImageGenerationQueued();
                    $environment->update(['generation_error' => null]);
                }
            }

            if ($task->type === AdstoryAiTask::TYPE_GENERATE_STORYBOARD_IMAGE_FOR_SHOT && $task->taskable_id) {
                $shot = AdstoryShot::query()->find($task->taskable_id);

                if ($shot && $shot->image_status !== AdstoryStoryboardService::IMAGE_STATUS_COMPLETED) {
                    $shot->markStoryboardImageQueued();
                }
            }

            Log::info('Adstory AI task: requeued for retry', [
                'task_id' => $task->id,
                'attempt' => $task->attempt_count,
                'max_attempts' => $task->max_attempts,
            ]);

            return;
        }

        $task->update([
            'status' => AdstoryAiTask::STATUS_FAILED,
            'error' => $message,
            'failed_at' => now(),
        ]);

        $this->taskProcessor()->handleTaskFailure($task, $message);
    }

    private function resetRelatedModelForRetry(AdstoryAiTask $task): void
    {
        if ($task->type === AdstoryAiTask::TYPE_GENERATE_SCENE && $task->taskable_id) {
            AdstoryScene::query()
                ->where('id', $task->taskable_id)
                ->update([
                    'status' => AdstorySceneGenerationService::SCENE_STATUS_QUEUED,
                    'generation_error' => null,
                ]);
        }

        if ($task->type === AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE && $task->taskable_id) {
            $scene = AdstoryScene::query()->find($task->taskable_id);

            if ($scene) {
                $scene->markShotGenerationQueued();
            }
        }

        if ($task->type === AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE && $task->taskable_id) {
            $character = AdstoryCharacter::query()->find($task->taskable_id);

            if ($character && $character->image_status !== AdstoryCharacterGenerationService::IMAGE_STATUS_COMPLETED) {
                $character->markImageGenerationQueued();
                $character->update(['generation_error' => null]);
            }
        }

        if ($task->type === AdstoryAiTask::TYPE_GENERATE_STORYBOARD_IMAGE_FOR_SHOT && $task->taskable_id) {
            $shot = AdstoryShot::query()->find($task->taskable_id);

            if ($shot && $shot->image_status !== AdstoryStoryboardService::IMAGE_STATUS_COMPLETED) {
                $shot->markStoryboardImageQueued();
            }
        }
    }

    private function releaseTaskLock(?AdstoryAiTask $task): void
    {
        if (! $task || $task->status === AdstoryAiTask::STATUS_QUEUED) {
            return;
        }

        $task->update([
            'locked_at' => null,
            'locked_by' => null,
        ]);
    }

    private function workerIdentity(): string
    {
        $host = gethostname() ?: 'unknown-host';
        $pid = getmypid() ?: 0;

        return "{$host}:{$pid}";
    }

    private function isDeletedProjectError(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Project no longer exists.');
    }
}
