<?php

namespace App\Services\Adstory;

use App\Models\AdstoryAiTask;
use App\Models\AdstoryEnvironment;
use App\Models\AdstoryProject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdstoryEnvironmentGenerationService
{
    public const IMAGE_STATUS_PENDING = 'pending';

    public const IMAGE_STATUS_QUEUED = 'queued';

    public const IMAGE_STATUS_GENERATING = 'generating';

    public const IMAGE_STATUS_COMPLETED = 'completed';

    public const IMAGE_STATUS_FAILED = 'failed';

    public const PROJECT_STATUS_IDLE = 'idle';

    public const PROJECT_STATUS_RUNNING = 'running';

    public const PROJECT_STATUS_COMPLETED = 'completed';

    public const PROJECT_STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const PROJECT_STATUS_FAILED = 'failed';

    public const PROJECT_STATUS_CANCELLED = 'cancelled';

    public function __construct(
        private readonly AdstoryAiTaskService $aiTaskService,
    ) {}

    /**
     * Extract environments only — does not queue image generation.
     *
     * @return array<string, mixed>
     */
    public function startGeneration(AdstoryProject $project, ?string $style = null): array
    {
        $this->assertScreenplayOrScenes($project);

        if ($project->environments()->exists()) {
            Log::info('Adstory environment-generation: environments exist, queueing image tasks', [
                'project_id' => $project->id,
            ]);

            return $this->startImageGeneration($project, $style);
        }

        if (AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS)
            ->exists()) {
            return array_merge(
                $this->buildProgressPayload($project),
                ['started' => false],
            );
        }

        DB::transaction(function () use ($project, $style) {
            $this->aiTaskService->createTask(
                project: $project,
                type: AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS,
                taskable: $project,
                payload: [
                    'project_id' => $project->id,
                    'style' => $style ?? $project->visual_style,
                ],
                priority: 8000,
            );

            $project->update([
                'environment_generation_status' => self::PROJECT_STATUS_RUNNING,
                'environment_generation_total' => 0,
                'environment_generation_completed' => 0,
                'environment_generation_failed' => 0,
                'environment_generation_started_at' => now(),
                'environment_generation_finished_at' => null,
                'current_step' => 'environments',
            ]);
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory environment-generation: extract task created and worker dispatched', [
            'project_id' => $project->id,
        ]);

        return array_merge(
            $this->buildProgressPayload($project->fresh()),
            ['started' => true],
        );
    }

    public function markExtractionCompleted(AdstoryProject $project, int $environmentCount): void
    {
        $project->update([
            'environment_generation_status' => self::PROJECT_STATUS_IDLE,
            'environment_generation_total' => $environmentCount,
            'environment_generation_completed' => 0,
            'environment_generation_failed' => 0,
            'environment_generation_finished_at' => null,
            'current_step' => 'environments',
        ]);

        Log::info('Adstory environment-generation: extraction completed, images not queued', [
            'project_id' => $project->id,
            'environments_count' => $environmentCount,
        ]);
    }

    /**
     * Queue sequential hero-image tasks for all incomplete environments.
     *
     * @return array<string, mixed>
     */
    public function startImageGeneration(AdstoryProject $project, ?string $style = null): array
    {
        if (! $project->environments()->exists()) {
            throw new RuntimeException('No environments found. Extract environments first.');
        }

        $hasActiveImageTasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE)
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActiveImageTasks) {
            return array_merge(
                $this->buildProgressPayload($project),
                ['started' => false],
            );
        }

        $created = $this->ensureMissingImageTasks($project, $style);

        if ($created === 0) {
            return array_merge(
                $this->buildProgressPayload($project),
                ['started' => false],
            );
        }

        $project->update([
            'environment_generation_status' => self::PROJECT_STATUS_RUNNING,
            'environment_generation_started_at' => $project->environment_generation_started_at ?? now(),
            'environment_generation_finished_at' => null,
        ]);

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory environment-generation: image tasks queued', [
            'project_id' => $project->id,
            'tasks_created' => $created,
        ]);

        return array_merge(
            $this->buildProgressPayload($project->fresh()),
            ['started' => true, 'tasks_created' => $created],
        );
    }

    /**
     * @deprecated Use startImageGeneration() — kept for internal compatibility.
     */
    public function queueImageGenerationTasks(AdstoryProject $project, ?string $style = null): void
    {
        $this->startImageGeneration($project, $style);
    }

    /**
     * Regenerate hero image for one environment.
     *
     * @return array<string, mixed>
     */
    public function regenerateEnvironmentImage(
        AdstoryProject $project,
        AdstoryEnvironment $environment,
        ?string $style = null,
    ): array {
        if ($environment->adstory_project_id !== $project->id) {
            throw new RuntimeException('Environment not found for this project.');
        }

        DB::transaction(function () use ($project, $environment, $style) {
            AdstoryAiTask::query()
                ->where('adstory_project_id', $project->id)
                ->where('type', AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE)
                ->where('taskable_id', $environment->id)
                ->delete();

            $environment->markImageGenerationQueued();
            $environment->update([
                'image_url' => null,
                'generation_error' => null,
                'prompt' => null,
            ]);

            $this->aiTaskService->createTask(
                project: $project,
                type: AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE,
                taskable: $environment,
                payload: [
                    'project_id' => $project->id,
                    'environment_id' => $environment->id,
                    'style' => $style ?? $project->visual_style,
                    'regenerate' => true,
                ],
                priority: 7500,
            );

            $project->update([
                'environment_generation_status' => self::PROJECT_STATUS_RUNNING,
                'environment_generation_finished_at' => null,
            ]);
        });

        $this->aiTaskService->dispatchWorker();

        return array_merge(
            $this->buildProgressPayload($project->fresh()),
            ['started' => true, 'regenerated_environment_id' => $environment->id],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function retryEnvironment(AdstoryProject $project, AdstoryEnvironment $environment, ?string $style = null): array
    {
        return $this->regenerateEnvironmentImage($project, $environment, $style);
    }

    /**
     * Regenerate hero images for every environment in order.
     *
     * @return array<string, mixed>
     */
    public function regenerateAllEnvironmentImages(AdstoryProject $project, ?string $style = null): array
    {
        if (! $project->environments()->exists()) {
            throw new RuntimeException('No environments found for this project.');
        }

        $style = $style ?? $project->visual_style;
        $created = 0;

        DB::transaction(function () use ($project, $style, &$created) {
            AdstoryAiTask::query()
                ->where('adstory_project_id', $project->id)
                ->where('type', AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE)
                ->delete();

            $environments = AdstoryEnvironment::query()
                ->where('adstory_project_id', $project->id)
                ->orderBy('order_index')
                ->orderBy('id')
                ->get();

            foreach ($environments as $index => $environment) {
                $environment->markImageGenerationQueued();
                $environment->update([
                    'image_url' => null,
                    'generation_error' => null,
                    'prompt' => null,
                ]);

                $this->aiTaskService->createTask(
                    project: $project,
                    type: AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE,
                    taskable: $environment,
                    payload: [
                        'project_id' => $project->id,
                        'environment_id' => $environment->id,
                        'style' => $style,
                        'regenerate' => true,
                    ],
                    priority: 7000 - $index,
                );

                $created++;
            }

            $project->update([
                'environment_generation_status' => self::PROJECT_STATUS_RUNNING,
                'environment_generation_total' => $environments->count(),
                'environment_generation_completed' => 0,
                'environment_generation_failed' => 0,
                'environment_generation_started_at' => now(),
                'environment_generation_finished_at' => null,
            ]);
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory environment-generation: all images queued for regeneration', [
            'project_id' => $project->id,
            'tasks_created' => $created,
        ]);

        return array_merge(
            $this->buildProgressPayload($project->fresh()),
            ['started' => true, 'tasks_created' => $created, 'regenerated_all' => true],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getProgress(AdstoryProject $project): array
    {
        return $this->buildProgressPayload($project);
    }

    public function finalizeProjectFromEnvironments(AdstoryProject $project): void
    {
        $project->load(['environments' => fn ($query) => $query->orderBy('order_index')->orderBy('id')]);

        $this->finalizeProjectIfDone($project, $project->environments);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelGeneration(AdstoryProject $project): array
    {
        if (! in_array($project->environment_generation_status, [
            self::PROJECT_STATUS_RUNNING,
        ], true)) {
            throw new RuntimeException('No active environment generation to cancel.');
        }

        $cancelledCount = $this->aiTaskService->cancelQueuedTasksByTypes($project->id, [
            AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS,
            AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE,
        ]);

        $project->update([
            'environment_generation_status' => self::PROJECT_STATUS_CANCELLED,
            'environment_generation_finished_at' => now(),
        ]);

        Log::info('Adstory environment-generation: cancelled', [
            'project_id' => $project->id,
            'cancelled_tasks' => $cancelledCount,
        ]);

        return $this->buildProgressPayload($project->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function resumeGeneration(AdstoryProject $project, bool $retryFailed = false, ?string $style = null): array
    {
        $staleReset = $this->aiTaskService->resetStaleRunningTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE
        );

        if ($staleReset > 0) {
            Log::warning('Adstory environment-generation: stale tasks reset during resume', [
                'project_id' => $project->id,
                'count' => $staleReset,
            ]);
        }

        $this->resetStuckGeneratingEnvironments($project);

        if ($retryFailed) {
            $this->aiTaskService->resetFailedEnvironmentImageTasks($project->id);
        }

        $created = $this->ensureMissingImageTasks($project, $style);

        if (
            $created > 0 ||
            $project->environment_generation_status === self::PROJECT_STATUS_CANCELLED
        ) {
            $project->update([
                'environment_generation_status' => self::PROJECT_STATUS_RUNNING,
                'environment_generation_finished_at' => null,
            ]);
        }

        Log::info('Adstory environment-generation: resume dispatched', [
            'project_id' => $project->id,
            'retry_failed' => $retryFailed,
            'tasks_created' => $created,
        ]);

        $this->aiTaskService->dispatchWorker();

        return array_merge(
            $this->buildProgressPayload($project->fresh()),
            ['resumed' => true, 'tasks_created' => $created],
        );
    }

    /**
     * Create image tasks for incomplete environments that have no pending task.
     */
    public function ensureMissingImageTasks(AdstoryProject $project, ?string $style = null): int
    {
        $style = $style ?? $project->visual_style;
        $created = 0;

        $environments = AdstoryEnvironment::query()
            ->where('adstory_project_id', $project->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        foreach ($environments as $index => $environment) {
            if ($this->resolveImageStatus($environment) === self::IMAGE_STATUS_COMPLETED) {
                continue;
            }

            $hasPendingTask = AdstoryAiTask::query()
                ->where('adstory_project_id', $project->id)
                ->where('type', AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE)
                ->where('taskable_id', $environment->id)
                ->whereIn('status', [
                    AdstoryAiTask::STATUS_QUEUED,
                    AdstoryAiTask::STATUS_RUNNING,
                ])
                ->exists();

            if ($hasPendingTask) {
                continue;
            }

            $environment->markImageGenerationQueued();
            $environment->update(['generation_error' => null]);

            $this->aiTaskService->createTask(
                project: $project,
                type: AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE,
                taskable: $environment,
                payload: [
                    'project_id' => $project->id,
                    'environment_id' => $environment->id,
                    'style' => $style,
                ],
                priority: 7000 - $index,
            );

            $created++;
        }

        if ($created > 0) {
            $project->update([
                'environment_generation_total' => $environments->count(),
            ]);
        }

        return $created;
    }

    private function resetStuckGeneratingEnvironments(AdstoryProject $project): void
    {
        AdstoryEnvironment::query()
            ->where('adstory_project_id', $project->id)
            ->where('image_status', self::IMAGE_STATUS_GENERATING)
            ->each(function (AdstoryEnvironment $environment) use ($project) {
                $hasRunningTask = AdstoryAiTask::query()
                    ->where('adstory_project_id', $project->id)
                    ->where('type', AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE)
                    ->where('taskable_id', $environment->id)
                    ->where('status', AdstoryAiTask::STATUS_RUNNING)
                    ->exists();

                if (! $hasRunningTask) {
                    $environment->markImageGenerationQueued();
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function detectStalledState(AdstoryProject $project, Collection $environments): array
    {
        $queuedTasks = $this->aiTaskService->countEligibleQueuedTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE
        );
        $runningTasks = $this->aiTaskService->countRunningTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE
        );
        $hasStale = $this->aiTaskService->hasStaleRunningTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE
        );

        $incompleteEnvironments = $environments->filter(
            fn (AdstoryEnvironment $environment) => $this->resolveImageStatus($environment) !== self::IMAGE_STATUS_COMPLETED
        );

        $missingTasks = $incompleteEnvironments->filter(function (AdstoryEnvironment $environment) use ($project) {
            return ! AdstoryAiTask::query()
                ->where('adstory_project_id', $project->id)
                ->where('type', AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE)
                ->where('taskable_id', $environment->id)
                ->whereIn('status', [
                    AdstoryAiTask::STATUS_QUEUED,
                    AdstoryAiTask::STATUS_RUNNING,
                ])
                ->exists();
        })->count();

        $stalled = $hasStale
            || ($queuedTasks > 0 && $runningTasks === 0)
            || ($incompleteEnvironments->isNotEmpty() && $missingTasks > 0 && $runningTasks === 0);

        if ($stalled) {
            Log::warning('Adstory environment-generation: stalled state detected', [
                'project_id' => $project->id,
                'queued_tasks' => $queuedTasks,
                'running_tasks' => $runningTasks,
                'missing_tasks' => $missingTasks,
                'has_stale' => $hasStale,
            ]);
        }

        return [
            'stalled' => $stalled,
            'queued_tasks' => $queuedTasks,
            'running_tasks' => $runningTasks,
            'missing_tasks' => $missingTasks,
        ];
    }

    /**
     * @param  Collection<int, AdstoryEnvironment>  $environments
     * @return list<array<string, mixed>>
     */
    private function buildFailedEnvironmentsList(Collection $environments): array
    {
        return $environments
            ->filter(fn (AdstoryEnvironment $environment) => $this->resolveImageStatus($environment) === self::IMAGE_STATUS_FAILED)
            ->map(fn (AdstoryEnvironment $environment) => [
                'id' => $environment->id,
                'name' => $environment->name,
                'image_status' => $environment->image_status,
                'generation_error' => $environment->generation_error,
            ])
            ->values()
            ->all();
    }

    private function assertScreenplayOrScenes(AdstoryProject $project): void
    {
        $hasScreenplay = mb_strlen(trim((string) ($project->screenplay ?? ''))) >= 20;
        $hasScenes = $project->scenes()
            ->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
            ->exists();

        if (! $hasScreenplay && ! $hasScenes) {
            throw new RuntimeException('Project must have a screenplay or completed scenes before starting environment generation.');
        }
    }

    private function buildProgressPayload(AdstoryProject $project): array
    {
        $this->aiTaskService->resetStaleRunningTasks($project->id, AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS);
        $this->aiTaskService->resetStaleRunningTasks($project->id, AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE);
        $this->resetStuckGeneratingEnvironments($project);

        $project->refresh()->load([
            'environments' => fn ($query) => $query->orderBy('order_index')->orderBy('id'),
        ]);

        $environments = $project->environments->values();
        $extractCounts = $this->aiTaskService->getTaskCounts($project->id, AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS);
        $imageCounts = $this->aiTaskService->getTaskCounts($project->id, AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE);

        if ($environments->isEmpty()) {
            $progress = $this->buildExtractionPhaseProgress($extractCounts);
        } else {
            $progress = $this->buildImagePhaseProgress($environments, $imageCounts);
        }

        $this->finalizeProjectIfDone($project, $environments);
        $project->refresh();
        $environments = $project->environments()->orderBy('order_index')->orderBy('id')->get()->values();

        $stall = $this->detectStalledState($project, $environments);

        $status = $project->environment_generation_status ?? self::PROJECT_STATUS_IDLE;

        $hasActiveImageWork =
            (($imageCounts['queued'] ?? 0) + ($imageCounts['running'] ?? 0)) > 0
            || ($progress['remaining'] ?? 0) > 0;

        if (
            $status === self::PROJECT_STATUS_IDLE
            && $environments->isNotEmpty()
            && $hasActiveImageWork
        ) {
            $status = self::PROJECT_STATUS_RUNNING;
        }

        if (
            $status === self::PROJECT_STATUS_IDLE
            && $environments->isNotEmpty()
            && ($progress['completed'] ?? 0) === ($progress['total'] ?? 0)
            && ($progress['total'] ?? 0) > 0
            && $this->countEnvironmentsByImageStatus($environments, self::IMAGE_STATUS_PENDING) === 0
        ) {
            $status = self::PROJECT_STATUS_COMPLETED;
        }

        $environmentRows = $environments
            ->map(fn (AdstoryEnvironment $environment) => $environment->toApiArray())
            ->values()
            ->all();

        return array_merge($progress, [
            'success' => true,
            'project_id' => $project->id,
            'status' => $status,
            'stalled' => $stall['stalled'],
            'queued_tasks' => $stall['queued_tasks'],
            'running_tasks' => $stall['running_tasks'],
            'missing_tasks' => $stall['missing_tasks'],
            'failed_environments' => $this->buildFailedEnvironmentsList($environments),
            'project' => $project->toApiArray(),
            'environments' => $environmentRows,
            'extraction' => [
                'queued' => $extractCounts['queued'],
                'running' => $extractCounts['running'],
                'completed' => $extractCounts['completed'],
                'failed' => $extractCounts['failed'],
            ],
            'tasks' => [
                'queued' => $imageCounts['queued'],
                'running' => $imageCounts['running'],
                'completed' => $imageCounts['completed'],
                'failed' => $imageCounts['failed'],
            ],
        ]);
    }

    /**
     * @param  array<string, int>  $extractCounts
     * @return array<string, mixed>
     */
    private function buildExtractionPhaseProgress(array $extractCounts): array
    {
        $completed = $extractCounts['completed'] > 0 ? 1 : 0;
        $failed = $extractCounts['failed'] > 0 ? 1 : 0;
        $remaining = max(0, 1 - $completed - $failed);

        return [
            'total' => 1,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $extractCounts['running'],
            'queued' => $extractCounts['queued'],
            'remaining' => $remaining,
            'progress_percent' => $completed > 0 ? 100 : 0,
            'estimated_remaining' => $remaining > 0 ? null : 0,
            'current_environment' => null,
            'phase' => 'extraction',
        ];
    }

    /**
     * @param  Collection<int, AdstoryEnvironment>  $environments
     * @param  array<string, int>  $imageCounts
     * @return array<string, mixed>
     */
    private function buildImagePhaseProgress(Collection $environments, array $imageCounts = []): array
    {
        $total = $environments->count();
        $completed = $this->countEnvironmentsByImageStatus($environments, self::IMAGE_STATUS_COMPLETED);
        $failed = $this->countEnvironmentsByImageStatus($environments, self::IMAGE_STATUS_FAILED);
        $running = $this->countEnvironmentsByImageStatus($environments, self::IMAGE_STATUS_GENERATING);
        $queued = $this->countEnvironmentsByImageStatus($environments, self::IMAGE_STATUS_QUEUED);
        $remaining = $environments->filter(
            fn (AdstoryEnvironment $environment) => ! in_array(
                $this->resolveImageStatus($environment),
                [self::IMAGE_STATUS_COMPLETED, self::IMAGE_STATUS_FAILED],
                true
            )
        )->count();

        if ($running === 0 && ($imageCounts['running'] ?? 0) > 0) {
            $running = $imageCounts['running'];
        }

        if ($queued === 0 && ($imageCounts['queued'] ?? 0) > 0) {
            $queued = $imageCounts['queued'];
        }

        $progressPercent = $total > 0 ? (int) round((($completed + $failed) / $total) * 100) : 0;

        $currentEnvironment = $environments->first(
            fn (AdstoryEnvironment $environment) => $this->resolveImageStatus($environment) === self::IMAGE_STATUS_GENERATING
        ) ?? $environments->first(
            fn (AdstoryEnvironment $environment) => $this->resolveImageStatus($environment) === self::IMAGE_STATUS_QUEUED
        ) ?? $environments->first(
            fn (AdstoryEnvironment $environment) => $this->resolveImageStatus($environment) === self::IMAGE_STATUS_PENDING
        );

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'queued' => $queued,
            'remaining' => $remaining,
            'progress_percent' => $progressPercent,
            'estimated_remaining' => $remaining > 0 ? null : 0,
            'current_environment' => $currentEnvironment
                ? $this->toEnvironmentProgress($currentEnvironment)
                : null,
            'phase' => 'images',
        ];
    }

    /**
     * @param  Collection<int, AdstoryEnvironment>  $environments
     */
    private function finalizeProjectIfDone(AdstoryProject $project, Collection $environments): void
    {
        if ($environments->isNotEmpty()) {
            $total = $environments->count();
            $completed = $this->countEnvironmentsByImageStatus($environments, self::IMAGE_STATUS_COMPLETED);
            $failed = $this->countEnvironmentsByImageStatus($environments, self::IMAGE_STATUS_FAILED);
            $incomplete = $total - $completed - $failed;

            if ($incomplete > 0 && in_array($project->environment_generation_status, [
                self::PROJECT_STATUS_COMPLETED,
                self::PROJECT_STATUS_COMPLETED_WITH_ERRORS,
            ], true)) {
                $project->update([
                    'environment_generation_status' => self::PROJECT_STATUS_RUNNING,
                    'environment_generation_finished_at' => null,
                ]);
            }
        }

        if (in_array($project->environment_generation_status, [
            self::PROJECT_STATUS_COMPLETED,
            self::PROJECT_STATUS_COMPLETED_WITH_ERRORS,
            self::PROJECT_STATUS_FAILED,
        ], true)) {
            $project->refresh();

            if ($environments->isEmpty()) {
                return;
            }

            $total = $environments->count();
            $completed = $this->countEnvironmentsByImageStatus($environments, self::IMAGE_STATUS_COMPLETED);
            $failed = $this->countEnvironmentsByImageStatus($environments, self::IMAGE_STATUS_FAILED);

            if (($completed + $failed) >= $total) {
                return;
            }
        }

        $hasActiveExtract = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS)
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActiveExtract) {
            return;
        }

        if ($environments->isEmpty()) {
            if ($project->environment_generation_status !== self::PROJECT_STATUS_RUNNING) {
                return;
            }

            $extractFailed = AdstoryAiTask::query()
                ->where('adstory_project_id', $project->id)
                ->where('type', AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS)
                ->where('status', AdstoryAiTask::STATUS_FAILED)
                ->exists();

            if ($extractFailed) {
                $project->update([
                    'environment_generation_status' => self::PROJECT_STATUS_FAILED,
                    'environment_generation_finished_at' => now(),
                ]);
            }

            return;
        }

        $hasActiveImageTasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE)
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActiveImageTasks) {
            return;
        }

        $total = $environments->count();
        $completed = $this->countEnvironmentsByImageStatus($environments, self::IMAGE_STATUS_COMPLETED);
        $failed = $this->countEnvironmentsByImageStatus($environments, self::IMAGE_STATUS_FAILED);

        if (($completed + $failed) < $total) {
            return;
        }

        $status = $failed > 0
            ? self::PROJECT_STATUS_COMPLETED_WITH_ERRORS
            : self::PROJECT_STATUS_COMPLETED;

        $project->update([
            'environment_generation_status' => $status,
            'environment_generation_total' => $total,
            'environment_generation_completed' => $completed,
            'environment_generation_failed' => $failed,
            'environment_generation_finished_at' => $project->environment_generation_finished_at ?? now(),
        ]);
    }

    /**
     * @param  Collection<int, AdstoryEnvironment>  $environments
     */
    private function countEnvironmentsByImageStatus(Collection $environments, string $status): int
    {
        return $environments
            ->filter(fn (AdstoryEnvironment $environment) => $this->resolveImageStatus($environment) === $status)
            ->count();
    }

    private function resolveImageStatus(AdstoryEnvironment $environment): ?string
    {
        $status = $environment->image_status;

        if ($status === self::IMAGE_STATUS_PENDING || $status === null || $status === '') {
            return ! empty($environment->image_url)
                ? self::IMAGE_STATUS_COMPLETED
                : self::IMAGE_STATUS_PENDING;
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     */
    private function toEnvironmentProgress(AdstoryEnvironment $environment): array
    {
        return [
            'id' => $environment->id,
            'db_id' => $environment->id,
            'name' => $environment->name,
            'image_status' => $this->resolveImageStatus($environment),
        ];
    }
}
