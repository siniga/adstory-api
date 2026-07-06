<?php

namespace App\Services\Adstory;

use App\Models\AdstoryCharacter;
use App\Models\AdstoryEnvironment;
use App\Models\AdstoryEpisode;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use Illuminate\Support\Facades\Log;

class AdstoryProjectFullLoaderService
{
    /** @var list<string> */
    public const ALLOWED_INCLUDES = [
        'episodes',
        'scenes',
        'shots',
        'characters',
        'environments',
    ];

    /** @var list<string> */
    private const PROJECT_COLUMNS = [
        'id',
        'title',
        'story',
        'script',
        'screenplay',
        'visual_style',
        'current_step',
        'status',
    ];

    /** @var list<string> */
    private const EPISODE_COLUMNS = [
        'id',
        'adstory_project_id',
        'episode_number',
        'title',
        'summary',
        'estimated_scene_count',
        'start_scene_number',
        'end_scene_number',
        'status',
        'scene_generation_status',
        'shot_generation_status',
    ];

    /** @var list<string> */
    private const SCENE_COLUMNS = [
        'id',
        'adstory_project_id',
        'adstory_episode_id',
        'scene_number',
        'title',
        'location',
        'environment',
        'time_of_day',
        'description',
        'mood',
        'visual_style',
        'status',
        'shot_generation_status',
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
        'meta',
        'image_url',
        'status',
        'order_index',
    ];

    /** @var list<string> */
    private const CHARACTER_COLUMNS = [
        'id',
        'adstory_project_id',
        'name',
        'role',
        'description',
        'image_url',
        'image_status',
        'status',
        'order_index',
    ];

    /** @var list<string> */
    private const ENVIRONMENT_COLUMNS = [
        'id',
        'adstory_project_id',
        'name',
        'description',
        'image_url',
        'image_status',
        'status',
        'order_index',
    ];

    public function __construct(
        private readonly AdstorySceneboardService $sceneboardService,
        private readonly AdstoryEpisodeLoaderService $episodeLoaderService,
    ) {}

    /**
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    public function load(AdstoryProject $project, array $includes = []): array
    {
        $memoryBefore = $this->memoryUsageMb();

        Log::info('Adstory project full: load started', [
            'project_id' => $project->id,
            'includes' => $includes,
            'memory_mb_before' => $memoryBefore,
        ]);

        $query = AdstoryProject::query()
            ->select(self::PROJECT_COLUMNS)
            ->whereKey($project->id);

        $with = $this->buildEagerLoads($includes);
        if ($with !== []) {
            $query->with($with);
        }

        /** @var AdstoryProject $loaded */
        $loaded = $query->firstOrFail();

        $payload = $this->mapProject($loaded, $includes);

        $memoryAfter = $this->memoryUsageMb();

        Log::info('Adstory project full: load finished', [
            'project_id' => $project->id,
            'includes' => $includes,
            'memory_mb_before' => $memoryBefore,
            'memory_mb_after' => $memoryAfter,
            'memory_mb_delta' => round($memoryAfter - $memoryBefore, 2),
            'counts' => $payload['counts'] ?? [],
        ]);

        return $payload;
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    private function buildEagerLoads(array $includes): array
    {
        $with = [];

        if (in_array('episodes', $includes, true)) {
            $with['episodes'] = fn ($query) => $query
                ->select(self::EPISODE_COLUMNS)
                ->orderBy('episode_number')
                ->orderBy('id');
        }

        if (in_array('scenes', $includes, true)) {
            $with['scenes'] = fn ($query) => $query
                ->select(self::SCENE_COLUMNS)
                ->orderBy('order_index')
                ->orderBy('id');
        }

        if (in_array('shots', $includes, true)) {
            $with['shots'] = fn ($query) => $query
                ->select(self::SHOT_COLUMNS)
                ->orderBy('order_index')
                ->orderBy('id');
        }

        if (in_array('characters', $includes, true)) {
            $with['characters'] = fn ($query) => $query
                ->select(self::CHARACTER_COLUMNS)
                ->orderBy('order_index')
                ->orderBy('id');
        }

        if (in_array('environments', $includes, true)) {
            $with['environments'] = fn ($query) => $query
                ->select(self::ENVIRONMENT_COLUMNS)
                ->orderBy('order_index')
                ->orderBy('id');
        }

        return $with;
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, mixed>
     */
    private function mapProject(AdstoryProject $project, array $includes): array
    {
        $payload = [
            'id' => $project->id,
            'title' => $project->title,
            'story' => $project->story,
            'script' => $project->script,
            'screenplay' => $project->screenplay,
            'visual_style' => $project->visual_style,
            'current_step' => $project->current_step,
            'status' => $project->status,
            'counts' => $this->sceneboardService->projectCounts($project->id),
            'scenes_summary' => $this->sceneboardService->scenesSummaryForProject($project->id),
        ];

        if (in_array('episodes', $includes, true)) {
            $payload['episodes_summary'] = $this->episodeLoaderService->episodeSummariesForProject($project->id);
        }

        if ($project->relationLoaded('episodes')) {
            $payload['episodes'] = $project->episodes
                ->map(fn (AdstoryEpisode $episode) => $episode->toApiArray())
                ->values()
                ->all();
        }

        if ($project->relationLoaded('scenes')) {
            $payload['scenes'] = $project->scenes
                ->map(fn (AdstoryScene $scene) => $this->mapScene($scene))
                ->values()
                ->all();
        }

        if ($project->relationLoaded('shots')) {
            $payload['shots'] = $project->shots
                ->map(fn (AdstoryShot $shot) => $this->mapShot($shot))
                ->values()
                ->all();
        }

        if ($project->relationLoaded('characters')) {
            $payload['characters'] = $project->characters
                ->map(fn (AdstoryCharacter $character) => $this->mapCharacter($character))
                ->values()
                ->all();
        }

        if ($project->relationLoaded('environments')) {
            $payload['environments'] = $project->environments
                ->map(fn (AdstoryEnvironment $environment) => $this->mapEnvironment($environment))
                ->values()
                ->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapScene(AdstoryScene $scene): array
    {
        return [
            'id' => $scene->id,
            'adstory_episode_id' => $scene->adstory_episode_id,
            'scene_number' => $scene->scene_number,
            'title' => $scene->title,
            'location' => $scene->location,
            'environment' => $scene->environment,
            'time_of_day' => $scene->time_of_day,
            'description' => $scene->description,
            'mood' => $scene->mood,
            'visual_style' => $scene->visual_style,
            'status' => $scene->status,
            'shot_generation_status' => $scene->shot_generation_status,
            'order_index' => $scene->order_index,
            'meta' => $scene->meta ?? [],
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
            'adstory_scene_id' => $shot->adstory_scene_id,
            'shot_number' => $shot->shot_number,
            'title' => $shot->title,
            'description' => $shot->description,
            'shot_size' => $shot->shot_size,
            'camera_angle' => $shot->camera_angle,
            'camera_movement' => $shot->camera_movement,
            'composition' => $shot->composition,
            'lighting' => $shot->lighting,
            'mood' => $meta['mood'] ?? null,
            'image_url' => $shot->image_url,
            'status' => $shot->status,
            'order_index' => $shot->order_index,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapCharacter(AdstoryCharacter $character): array
    {
        return [
            'id' => $character->id,
            'name' => $character->name,
            'role' => $character->role,
            'description' => $character->description,
            'image_url' => $character->image_url,
            'image_status' => $character->image_status,
            'status' => $character->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEnvironment(AdstoryEnvironment $environment): array
    {
        return [
            'id' => $environment->id,
            'name' => $environment->name,
            'description' => $environment->description,
            'image_url' => $environment->image_url,
            'image_status' => $environment->image_status,
            'status' => $environment->status,
        ];
    }

    /**
     * @return list<string>
     */
    public function parseIncludes(?string $include): array
    {
        if ($include === null || trim($include) === '') {
            return [];
        }

        $requested = array_map('trim', explode(',', strtolower($include)));

        return array_values(array_intersect($requested, self::ALLOWED_INCLUDES));
    }

    private function memoryUsageMb(): float
    {
        return round(memory_get_usage(true) / 1024 / 1024, 2);
    }
}
