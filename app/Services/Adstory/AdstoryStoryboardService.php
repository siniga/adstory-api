<?php

namespace App\Services\Adstory;

use App\Models\AdstoryAiTask;
use App\Models\AdstoryCharacter;
use App\Models\AdstoryEnvironment;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdstoryStoryboardService
{
    public const IMAGE_STATUS_QUEUED = 'queued';

    public const IMAGE_STATUS_GENERATING = 'generating';

    public const IMAGE_STATUS_COMPLETED = 'completed';

    public const IMAGE_STATUS_FAILED = 'failed';

    /** @var list<string> */
    private const SCENE_LIST_COLUMNS = [
        'id',
        'adstory_project_id',
        'scene_number',
        'title',
        'description',
        'location',
        'time_of_day',
        'mood',
        'status',
        'shot_generation_status',
        'order_index',
    ];

    /** @var list<string> */
    private const SHOT_COLUMNS = [
        'id',
        'adstory_project_id',
        'adstory_scene_id',
        'shot_number',
        'title',
        'description',
        'shot_size',
        'camera_angle',
        'camera_movement',
        'composition',
        'lighting',
        'environment',
        'characters',
        'prompt',
        'image_prompt',
        'meta',
        'image_url',
        'image_status',
        'generation_error',
        'status',
        'order_index',
    ];

    public function __construct(
        private readonly AdstoryAiTaskService $aiTaskService,
        private readonly AdstoryShotImageJobService $shotImageJobService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function loadProjectStoryboard(AdstoryProject $project): array
    {
        $scenes = AdstoryScene::query()
            ->where('adstory_project_id', $project->id)
            ->select(self::SCENE_LIST_COLUMNS)
            ->withCount('shots')
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        return [
            'success' => true,
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
            ],
            'scenes' => $scenes
                ->map(fn (AdstoryScene $scene) => $this->mapStoryboardSceneListItem($scene))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loadSceneStoryboard(AdstoryProject $project, AdstoryScene $scene): array
    {
        $this->assertSceneBelongsToProject($scene, $project);

        $scene->load(['shots' => fn ($query) => $query
            ->select(self::SHOT_COLUMNS)
            ->orderBy('order_index')
            ->orderBy('id'),
        ]);

        return [
            'success' => true,
            'scene' => $this->mapStoryboardSceneDetail($scene),
            'shots' => $scene->shots
                ->map(fn (AdstoryShot $shot) => $this->mapStoryboardShot($shot))
                ->values()
                ->all(),
            'characters' => $this->loadCharacterReferences($project->id),
            'environments' => $this->loadEnvironmentReferences($project->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function startSceneShotGeneration(
        AdstoryProject $project,
        AdstoryScene $scene,
        bool $force = false,
        ?string $style = null,
    ): array {
        $this->assertSceneBelongsToProject($scene, $project);
        $this->assertSceneCompleted($scene);

        if ($scene->shots()->exists() && ! $force) {
            return array_merge(
                $this->buildSceneShotProgress($project, $scene),
                ['started' => false],
            );
        }

        if ($this->hasActiveSceneShotTask($project, $scene) && ! $force) {
            return array_merge(
                $this->buildSceneShotProgress($project, $scene),
                ['started' => false],
            );
        }

        DB::transaction(function () use ($project, $scene, $force, $style) {
            if ($force) {
                $scene->shots()->delete();
                AdstoryAiTask::query()
                    ->where('adstory_project_id', $project->id)
                    ->where('type', AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE)
                    ->where('taskable_id', $scene->id)
                    ->delete();
            }

            $scene->markShotGenerationQueued();

            $this->aiTaskService->createTask(
                project: $project,
                type: AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE,
                taskable: $scene,
                payload: [
                    'project_id' => $project->id,
                    'scene_id' => $scene->id,
                    'scene_number' => $scene->scene_number,
                    'style' => $style ?? $project->visual_style,
                    'source' => 'storyboard',
                ],
                priority: 9000,
            );
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory storyboard: shot generation queued for scene', [
            'project_id' => $project->id,
            'scene_id' => $scene->id,
        ]);

        return array_merge(
            $this->buildSceneShotProgress($project, $scene->fresh()),
            ['started' => true],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function startSceneShotImageGeneration(
        AdstoryProject $project,
        AdstoryScene $scene,
        bool $force = false,
        ?string $style = null,
    ): array {
        $this->assertSceneBelongsToProject($scene, $project);

        $shots = AdstoryShot::query()
            ->where('adstory_project_id', $project->id)
            ->where('adstory_scene_id', $scene->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        if ($shots->isEmpty()) {
            throw new RuntimeException('This scene has no shots yet.');
        }

        if ($force) {
            AdstoryAiTask::query()
                ->where('adstory_project_id', $project->id)
                ->where('type', AdstoryAiTask::TYPE_GENERATE_STORYBOARD_IMAGE_FOR_SHOT)
                ->whereIn('taskable_id', $shots->pluck('id'))
                ->whereIn('status', [
                    AdstoryAiTask::STATUS_QUEUED,
                    AdstoryAiTask::STATUS_RUNNING,
                ])
                ->delete();
        }

        $queued = $this->shotImageJobService->queueShotImageJobsForScene(
            project: $project,
            scene: $scene,
            force: $force,
        );

        if ($queued > 0) {
            Log::info('Adstory storyboard: shot image jobs queued for scene', [
                'project_id' => $project->id,
                'scene_id' => $scene->id,
                'jobs_queued' => $queued,
                'style' => $style ?? $project->visual_style,
            ]);
        }

        return array_merge(
            $this->buildSceneShotImageProgress($project, $scene->fresh()),
            [
                'started' => $queued > 0,
                'tasks_created' => $queued,
                'jobs_queued' => $queued,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function resumeSceneShotImageGeneration(
        AdstoryProject $project,
        AdstoryScene $scene,
        bool $retryFailed = false,
        ?string $style = null,
    ): array {
        $this->assertSceneBelongsToProject($scene, $project);

        $staleReset = $this->shotImageJobService->resetStaleGeneratingShots($project->id, $scene->id);

        if ($staleReset > 0) {
            Log::warning('Adstory storyboard: stale shot image jobs reset during resume', [
                'project_id' => $project->id,
                'scene_id' => $scene->id,
                'count' => $staleReset,
            ]);
        }

        if ($retryFailed) {
            AdstoryShot::query()
                ->where('adstory_project_id', $project->id)
                ->where('adstory_scene_id', $scene->id)
                ->where('image_status', self::IMAGE_STATUS_FAILED)
                ->each(fn (AdstoryShot $shot) => $shot->markStoryboardImageQueued());
        }

        $queued = $this->shotImageJobService->ensureMissingShotImageJobs(
            project: $project,
            scene: $scene,
            includeFailed: $retryFailed,
        );

        Log::info('Adstory storyboard: shot image generation resumed', [
            'project_id' => $project->id,
            'scene_id' => $scene->id,
            'retry_failed' => $retryFailed,
            'jobs_queued' => $queued,
            'stale_reset' => $staleReset,
            'style' => $style ?? $project->visual_style,
        ]);

        return array_merge(
            $this->buildSceneShotImageProgress($project, $scene->fresh()),
            [
                'resumed' => true,
                'tasks_created' => $queued,
                'jobs_queued' => $queued,
                'stale_reset' => $staleReset,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelSceneShotGeneration(AdstoryProject $project, AdstoryScene $scene): array
    {
        $this->assertSceneBelongsToProject($scene, $project);

        $cancelled = $this->aiTaskService->cancelShotGenerationTaskForScene($project->id, $scene->id);

        $scene->refresh();
        $status = $this->resolveShotGenerationStatus($scene);

        if (
            in_array($status, [
                AdstoryShotGenerationService::SHOT_STATUS_QUEUED,
                AdstoryShotGenerationService::SHOT_STATUS_GENERATING,
            ], true)
            && ! $this->hasActiveSceneShotTask($project, $scene)
        ) {
            $scene->markShotGenerationCancelled();
        } elseif ($cancelled > 0) {
            $scene->markShotGenerationCancelled();
        }

        Log::info('Adstory storyboard: shot generation cancelled for scene', [
            'project_id' => $project->id,
            'scene_id' => $scene->id,
            'tasks_cancelled' => $cancelled,
        ]);

        return array_merge(
            $this->buildSceneShotProgress($project, $scene->fresh()),
            [
                'cancelled' => true,
                'tasks_cancelled' => $cancelled,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelSceneShotImageGeneration(AdstoryProject $project, AdstoryScene $scene): array
    {
        $this->assertSceneBelongsToProject($scene, $project);

        $cancelled = $this->shotImageJobService->cancelInFlightShotsForScene($project, $scene);

        Log::info('Adstory storyboard: shot image generation cancelled for scene', [
            'project_id' => $project->id,
            'scene_id' => $scene->id,
            'shots_cancelled' => $cancelled,
        ]);

        return array_merge(
            $this->buildSceneShotImageProgress($project, $scene->fresh()),
            [
                'cancelled' => true,
                'tasks_cancelled' => $cancelled,
                'shots_cancelled' => $cancelled,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSceneShotImageProgress(AdstoryProject $project, AdstoryScene $scene): array
    {
        $this->assertSceneBelongsToProject($scene, $project);

        $this->shotImageJobService->resetStaleGeneratingShots($project->id, $scene->id);

        $shots = AdstoryShot::query()
            ->where('adstory_project_id', $project->id)
            ->where('adstory_scene_id', $scene->id)
            ->select(self::SHOT_COLUMNS)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $total = $shots->count();
        $completed = 0;
        $failed = 0;
        $queued = 0;
        $generating = 0;

        foreach ($shots as $shot) {
            $status = $this->resolveStoryboardImageStatus($shot);

            match ($status) {
                self::IMAGE_STATUS_COMPLETED => $completed++,
                self::IMAGE_STATUS_FAILED => $failed++,
                self::IMAGE_STATUS_QUEUED => $queued++,
                self::IMAGE_STATUS_GENERATING => $generating++,
                default => null,
            };
        }

        $remaining = max(0, $queued + $generating);
        $progressPercent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        $stalled = $this->detectShotImageStalledState($shots);
        $currentShot = $this->resolveCurrentStoryboardShot($shots);

        return [
            'success' => true,
            'scene' => $this->mapStoryboardSceneDetail($scene),
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'remaining' => $remaining,
            'queued' => $queued,
            'generating' => $generating,
            'progress_percent' => $progressPercent,
            'stalled' => $stalled,
            'current_shot' => $currentShot,
            'shots' => $shots
                ->map(fn (AdstoryShot $shot) => $this->mapStoryboardShot($shot))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildProjectGenerationProgress(AdstoryProject $project): array
    {
        return $this->shotImageJobService->buildProjectGenerationProgress($project);
    }

    /**
     * @param  Collection<int, AdstoryShot>  $shots
     */
    private function detectShotImageStalledState(Collection $shots): bool
    {
        $staleThreshold = now()->subSeconds(AdstoryShotImageJobService::STALE_GENERATING_SECONDS);

        foreach ($shots as $shot) {
            $status = $this->resolveStoryboardImageStatus($shot);

            if ($status === self::IMAGE_STATUS_GENERATING) {
                $startedAt = $shot->image_generation_started_at ?? $shot->updated_at;

                if ($startedAt && $startedAt->lt($staleThreshold)) {
                    return true;
                }
            }

            if ($status === self::IMAGE_STATUS_QUEUED) {
                $queuedAt = $shot->updated_at;

                if ($queuedAt && $queuedAt->lt($staleThreshold)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, AdstoryShot>  $shots
     * @return array<string, mixed>|null
     */
    private function resolveCurrentStoryboardShot(Collection $shots): ?array
    {
        $generatingShot = $shots->first(
            fn (AdstoryShot $shot) => $this->resolveStoryboardImageStatus($shot) === self::IMAGE_STATUS_GENERATING
        );

        if ($generatingShot) {
            return $this->mapStoryboardShot($generatingShot);
        }

        $queuedShot = $shots->first(
            fn (AdstoryShot $shot) => $this->resolveStoryboardImageStatus($shot) === self::IMAGE_STATUS_QUEUED
        );

        if ($queuedShot) {
            return $this->mapStoryboardShot($queuedShot);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSceneShotProgress(AdstoryProject $project, AdstoryScene $scene): array
    {
        $this->assertSceneBelongsToProject($scene, $project);

        $this->aiTaskService->resetStaleRunningTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE
        );

        $scene->load(['shots' => fn ($query) => $query
            ->select(self::SHOT_COLUMNS)
            ->orderBy('order_index')
            ->orderBy('id'),
        ]);

        $status = $this->resolveShotGenerationStatus($scene);
        $taskState = $this->resolveTaskProgressState($project, $scene, $status);

        return [
            'success' => true,
            'scene' => $this->mapStoryboardSceneDetail($scene),
            'shot_generation_status' => $status,
            'total' => 1,
            'completed' => $taskState['completed'],
            'failed' => $taskState['failed'],
            'remaining' => $taskState['remaining'],
            'progress_percent' => $taskState['progress_percent'],
            'shots' => $scene->shots
                ->map(fn (AdstoryShot $shot) => $this->mapStoryboardShot($shot))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCharacterReferences(int $projectId): array
    {
        return AdstoryCharacter::query()
            ->where('adstory_project_id', $projectId)
            ->select(['id', 'name', 'role', 'image_url', 'image_status'])
            ->orderBy('order_index')
            ->orderBy('id')
            ->get()
            ->map(fn (AdstoryCharacter $character) => [
                'id' => $character->id,
                'name' => $character->name,
                'role' => $character->role,
                'image_url' => $character->image_url,
                'image_status' => $character->image_status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadEnvironmentReferences(int $projectId): array
    {
        return AdstoryEnvironment::query()
            ->where('adstory_project_id', $projectId)
            ->select(['id', 'name', 'location_type', 'type', 'image_url', 'image_status'])
            ->orderBy('order_index')
            ->orderBy('id')
            ->get()
            ->map(fn (AdstoryEnvironment $environment) => [
                'id' => $environment->id,
                'name' => $environment->name,
                'location_type' => $environment->location_type ?? $environment->type,
                'image_url' => $environment->image_url,
                'image_status' => $environment->image_status,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapStoryboardSceneListItem(AdstoryScene $scene): array
    {
        return [
            'id' => $scene->id,
            'scene_number' => $scene->scene_number,
            'title' => $scene->title,
            'description' => $scene->description,
            'location' => $scene->location,
            'time_of_day' => $scene->time_of_day,
            'mood' => $scene->mood,
            'shot_generation_status' => $this->resolveShotGenerationStatus($scene),
            'shots_count' => (int) ($scene->shots_count ?? $scene->shots()->count()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapStoryboardSceneDetail(AdstoryScene $scene): array
    {
        $meta = is_array($scene->meta ?? null) ? $scene->meta : [];

        return [
            'id' => $scene->id,
            'scene_number' => $scene->scene_number,
            'title' => $scene->title,
            'description' => $scene->description,
            'location' => $scene->location,
            'time_of_day' => $scene->time_of_day,
            'mood' => $scene->mood,
            'status' => $scene->status,
            'shot_generation_status' => $this->resolveShotGenerationStatus($scene),
            'shot_generation_error' => $scene->shot_generation_error ?? ($meta['shot_generation_error'] ?? null),
            'shots_count' => $scene->relationLoaded('shots')
                ? $scene->shots->count()
                : $scene->shots()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapStoryboardShot(AdstoryShot $shot): array
    {
        $meta = is_array($shot->meta ?? null) ? $shot->meta : [];

        return [
            'id' => $shot->id,
            'adstory_scene_id' => $shot->adstory_scene_id,
            'scene_id' => $shot->adstory_scene_id,
            'shot_number' => $shot->shot_number,
            'title' => $shot->title,
            'description' => $shot->description,
            'shot_size' => $shot->shot_size,
            'camera_angle' => $shot->camera_angle,
            'camera_movement' => $shot->camera_movement,
            'composition' => $shot->composition,
            'lighting' => $shot->lighting,
            'mood' => $meta['mood'] ?? null,
            'characters' => $shot->characters ?? [],
            'environment' => $shot->environment,
            'prompt' => $shot->prompt,
            'image_prompt' => $shot->image_prompt,
            'image_url' => $shot->image_url,
            'image_status' => $this->resolveStoryboardImageStatus($shot),
            'generation_error' => $shot->generation_error,
            'status' => $shot->status,
            'order_index' => $shot->order_index,
        ];
    }

    private function resolveShotGenerationStatus(AdstoryScene $scene): string
    {
        $meta = is_array($scene->meta ?? null) ? $scene->meta : [];
        $status = $scene->shot_generation_status ?? ($meta['shot_generation_status'] ?? null);

        if ($status !== null && $status !== '') {
            return (string) $status;
        }

        if ($scene->relationLoaded('shots') ? $scene->shots->isNotEmpty() : $scene->shots()->exists()) {
            return AdstoryShotGenerationService::SHOT_STATUS_COMPLETED;
        }

        return 'not_started';
    }

    /**
     * @return array{completed: int, failed: int, remaining: int, progress_percent: int}
     */
    private function resolveTaskProgressState(AdstoryProject $project, AdstoryScene $scene, string $status): array
    {
        if ($status === AdstoryShotGenerationService::SHOT_STATUS_COMPLETED) {
            return [
                'completed' => 1,
                'failed' => 0,
                'remaining' => 0,
                'progress_percent' => 100,
            ];
        }

        if ($status === AdstoryShotGenerationService::SHOT_STATUS_FAILED) {
            return [
                'completed' => 0,
                'failed' => 1,
                'remaining' => 0,
                'progress_percent' => 100,
            ];
        }

        if (in_array($status, [
            AdstoryShotGenerationService::SHOT_STATUS_QUEUED,
            AdstoryShotGenerationService::SHOT_STATUS_GENERATING,
        ], true) || $this->hasActiveSceneShotTask($project, $scene)) {
            return [
                'completed' => 0,
                'failed' => 0,
                'remaining' => 1,
                'progress_percent' => $status === AdstoryShotGenerationService::SHOT_STATUS_GENERATING ? 50 : 0,
            ];
        }

        return [
            'completed' => 0,
            'failed' => 0,
            'remaining' => 1,
            'progress_percent' => 0,
        ];
    }

    private function hasActiveSceneShotTask(AdstoryProject $project, AdstoryScene $scene): bool
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

    private function assertSceneBelongsToProject(AdstoryScene $scene, AdstoryProject $project): void
    {
        if ($scene->adstory_project_id !== $project->id) {
            throw new RuntimeException('Scene not found for this project.');
        }
    }

    private function assertSceneCompleted(AdstoryScene $scene): void
    {
        if ($scene->status !== AdstorySceneGenerationService::SCENE_STATUS_COMPLETED) {
            throw new RuntimeException('Scene must be completed before generating shots.');
        }
    }

    private function resolveStoryboardImageStatus(AdstoryShot $shot): string
    {
        $status = $shot->image_status ?? 'pending';

        if ($status === self::IMAGE_STATUS_COMPLETED && empty($shot->image_url)) {
            return 'pending';
        }

        return (string) $status;
    }
}
