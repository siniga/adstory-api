<?php

namespace App\Services\Adstory;

use App\Models\AdstoryAiTask;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdstorySceneboardService
{
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
        'visual_style',
        'status',
        'order_index',
    ];

    /** @var list<string> */
    private const SCENE_DETAIL_COLUMNS = [
        'id',
        'adstory_project_id',
        'scene_number',
        'title',
        'slug',
        'description',
        'location',
        'environment',
        'time_of_day',
        'mood',
        'visual_style',
        'status',
        'order_index',
        'meta',
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
        'meta',
        'image_url',
        'status',
        'order_index',
    ];

    /** @var list<string> */
    private const PROJECT_SUMMARY_COLUMNS = [
        'id',
        'title',
        'visual_style',
        'current_step',
        'status',
    ];

    public function __construct(
        private readonly AdstoryAiTaskService $aiTaskService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function loadProjectSceneboard(AdstoryProject $project): array
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
            'project' => $this->mapProjectSummary($project),
            'scenes' => $scenes
                ->map(fn (AdstoryScene $scene) => $this->mapSceneListItem($scene))
                ->values()
                ->all(),
            'next_step' => 'characters',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loadSceneSceneboard(AdstoryProject $project, AdstoryScene $scene): array
    {
        $this->assertSceneBelongsToProject($scene, $project);

        $scene->loadCount('shots');

        return [
            'success' => true,
            'scene' => $this->mapSceneDetail($scene),
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
        $this->assertCharactersReady($project);

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
                ],
                priority: 9000,
            );
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory sceneboard: shot generation queued for scene', [
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
            'scene' => $this->mapSceneDetail($scene),
            'shot_generation_status' => $status,
            'total' => 1,
            'completed' => $taskState['completed'],
            'failed' => $taskState['failed'],
            'remaining' => $taskState['remaining'],
            'progress_percent' => $taskState['progress_percent'],
            'shots' => $scene->shots
                ->map(fn (AdstoryShot $shot) => $this->mapShot($shot))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function scenesSummaryForProject(int $projectId): array
    {
        return AdstoryScene::query()
            ->where('adstory_project_id', $projectId)
            ->select([
                'id',
                'scene_number',
                'title',
                'status',
                'order_index',
            ])
            ->withCount('shots')
            ->orderBy('order_index')
            ->orderBy('id')
            ->get()
            ->map(fn (AdstoryScene $scene) => [
                'id' => $scene->id,
                'scene_number' => $scene->scene_number,
                'order_index' => (int) ($scene->order_index ?? 0),
                'title' => $scene->title,
                'status' => $scene->status,
                'shots_count' => (int) $scene->shots_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public function projectCounts(int $projectId): array
    {
        return [
            'scenes' => AdstoryScene::query()->where('adstory_project_id', $projectId)->count(),
            'shots' => AdstoryShot::query()->where('adstory_project_id', $projectId)->count(),
            'scenes_completed' => AdstoryScene::query()
                ->where('adstory_project_id', $projectId)
                ->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
                ->count(),
            'scenes_with_shots' => AdstoryScene::query()
                ->where('adstory_project_id', $projectId)
                ->whereHas('shots')
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProjectSummary(AdstoryProject $project): array
    {
        return [
            'id' => $project->id,
            'title' => $project->title,
            'visual_style' => $project->visual_style,
            'style' => $project->visual_style,
            'current_step' => $project->current_step,
            'status' => $project->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSceneListItem(AdstoryScene $scene): array
    {
        return [
            'id' => $scene->id,
            'scene_number' => $scene->scene_number,
            'order_index' => (int) ($scene->order_index ?? 0),
            'title' => $scene->title,
            'description' => $scene->description,
            'location' => $scene->location,
            'time_of_day' => $scene->time_of_day,
            'mood' => $scene->mood,
            'visual_style' => $scene->visual_style,
            'status' => $scene->status,
            'shots_count' => (int) ($scene->shots_count ?? $scene->shots()->count()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSceneDetail(AdstoryScene $scene): array
    {
        $meta = is_array($scene->meta ?? null) ? $scene->meta : [];

        return [
            'id' => $scene->id,
            'adstory_project_id' => $scene->adstory_project_id,
            'scene_number' => $scene->scene_number,
            'title' => $scene->title,
            'slug' => $scene->slug,
            'description' => $scene->description,
            'location' => $scene->location,
            'environment' => $scene->environment ?? ($meta['environment'] ?? null),
            'time_of_day' => $scene->time_of_day,
            'mood' => $scene->mood,
            'visual_style' => $scene->visual_style,
            'status' => $scene->status,
            'order_index' => $scene->order_index,
            'characters' => $meta['characters'] ?? [],
            'shots_count' => (int) ($scene->shots_count ?? (
                $scene->relationLoaded('shots')
                    ? $scene->shots->count()
                    : $scene->shots()->count()
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapShot(AdstoryShot $shot): array
    {
        $meta = is_array($shot->meta ?? null) ? $shot->meta : [];

        return [
            'id' => $shot->id,
            'adstory_project_id' => $shot->adstory_project_id,
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
            'image_url' => $shot->image_url,
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

    private function assertCharactersReady(AdstoryProject $project): void
    {
        if ($project->characters()->exists()) {
            return;
        }

        $extractCompleted = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_EXTRACT_CHARACTERS)
            ->where('status', AdstoryAiTask::STATUS_COMPLETED)
            ->exists();

        if ($extractCompleted) {
            return;
        }

        throw new RuntimeException(
            'Character generation must be completed before generating shots. Start with POST /api/adstory/projects/{project}/characters/start-generation.'
        );
    }
}
