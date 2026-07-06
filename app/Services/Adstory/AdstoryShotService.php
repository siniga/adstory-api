<?php

namespace App\Services\Adstory;

use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdstoryShotService
{
    /**
     * @param  array<int, array<string, mixed>>  $shotsData
     * @return array<int, array<string, mixed>>
     */
    public function replaceSceneShots(AdstoryProject $project, AdstoryScene $scene, array $shotsData): array
    {
        return DB::transaction(function () use ($project, $scene, $shotsData) {
            $scene->shots()->delete();

            $scenes = collect([$scene]);
            $savedShots = [];

            foreach ($shotsData as $index => $shotData) {
                $shotData['scene_number'] = $shotData['scene_number'] ?? $scene->scene_number;
                $shotData['adstory_scene_id'] = $scene->id;
                $shotData['status'] = AdstoryShotGenerationService::SHOT_STATUS_COMPLETED;

                if (empty($shotData['environment']) && ! empty($scene->environment)) {
                    $shotData['environment'] = $scene->environment;
                }

                if (empty($shotData['characters'])) {
                    $meta = is_array($scene->meta ?? null) ? $scene->meta : [];
                    $shotData['characters'] = $meta['characters'] ?? [];
                }

                $shot = AdstoryShot::query()->create(
                    $this->mapShotAttributes(
                        project: $project,
                        scenes: $scenes,
                        data: $shotData,
                        orderIndex: $index,
                        sceneIdOverride: $scene->id,
                        defaultStatus: AdstoryShotGenerationService::SHOT_STATUS_COMPLETED,
                    )
                );

                $savedShots[] = $shot->load('scene')->toApiArray();
            }

            return $savedShots;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $shotsData
     * @return array<int, array<string, mixed>>
     */
    public function replaceProjectShots(AdstoryProject $project, array $shotsData): array
    {
        return DB::transaction(function () use ($project, $shotsData) {
            $scenes = $project->scenes()->get();
            $project->shots()->delete();

            $savedShots = [];

            foreach ($shotsData as $index => $shotData) {
                $shot = AdstoryShot::query()->create(
                    $this->mapShotAttributes(
                        project: $project,
                        scenes: $scenes,
                        data: $shotData,
                        orderIndex: $index,
                    )
                );

                $savedShots[] = $shot->load('scene')->toApiArray();
            }

            $project->current_step = 'shots';
            $project->save();

            return $savedShots;
        });
    }

    /**
     * @param  Collection<int, AdstoryScene>  $scenes
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mapShotAttributes(
        AdstoryProject $project,
        Collection $scenes,
        array $data,
        int $orderIndex,
        ?int $sceneIdOverride = null,
        string $defaultStatus = 'draft',
    ): array {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        if (array_key_exists('scene_number', $data) && $data['scene_number'] !== null) {
            $meta['scene_number'] = $data['scene_number'];
        }

        if (array_key_exists('mood', $data)) {
            $meta['mood'] = $data['mood'];
        }

        $sceneId = $sceneIdOverride ?? $this->resolveSceneId($scenes, $data);

        $shotNumber = $data['shot_number'] ?? null;
        if ($shotNumber !== null) {
            $shotNumber = (string) $shotNumber;
        }

        $prompt = $data['prompt'] ?? $this->buildShotPromptFromData($data);

        return [
            'adstory_project_id' => $project->id,
            'adstory_scene_id' => $sceneId,
            'shot_number' => $shotNumber,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'action' => $data['action'] ?? null,
            'dialogue' => $data['dialogue'] ?? null,
            'shot_size' => $data['shot_size'] ?? null,
            'camera_angle' => $data['camera_angle'] ?? null,
            'camera_movement' => $data['camera_movement'] ?? null,
            'composition' => $data['composition'] ?? null,
            'lens' => $data['lens'] ?? null,
            'lighting' => $data['lighting'] ?? null,
            'environment' => $data['environment'] ?? null,
            'characters' => $data['characters'] ?? null,
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'prompt' => $prompt,
            'image_url' => $data['image_url'] ?? null,
            'image_status' => $data['image_status'] ?? 'pending',
            'order_index' => $data['order_index'] ?? $orderIndex,
            'status' => $data['status'] ?? $defaultStatus,
            'meta' => $meta === [] ? null : $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildShotPromptFromData(array $data): ?string
    {
        $parts = array_filter([
            $data['description'] ?? null,
            isset($data['shot_size']) ? 'Shot size: '.$data['shot_size'] : null,
            isset($data['camera_angle']) ? 'Camera angle: '.$data['camera_angle'] : null,
            isset($data['camera_movement']) ? 'Camera movement: '.$data['camera_movement'] : null,
            isset($data['composition']) ? 'Composition: '.$data['composition'] : null,
            isset($data['lighting']) ? 'Lighting: '.$data['lighting'] : null,
            isset($data['mood']) ? 'Mood: '.$data['mood'] : null,
            isset($data['environment']) ? 'Environment: '.$data['environment'] : null,
        ]);

        return $parts === [] ? null : implode('. ', $parts);
    }

    /**
     * @param  Collection<int, AdstoryScene>  $scenes
     * @param  array<string, mixed>  $data
     */
    public function resolveSceneId(Collection $scenes, array $data): ?int
    {
        if (! empty($data['adstory_scene_id'])) {
            $scene = $scenes->firstWhere('id', (int) $data['adstory_scene_id']);

            return $scene?->id;
        }

        if (! empty($data['scene_id'])) {
            $scene = $scenes->firstWhere('id', (int) $data['scene_id']);

            return $scene?->id;
        }

        if (isset($data['scene_number']) && $data['scene_number'] !== null && $data['scene_number'] !== '') {
            $sceneNumber = (int) $data['scene_number'];
            $scene = $scenes->firstWhere('scene_number', $sceneNumber);

            if ($scene) {
                return $scene->id;
            }
        }

        $sceneTitle = $data['scene_title'] ?? $data['scene'] ?? null;
        if (is_string($sceneTitle) && $sceneTitle !== '') {
            $scene = $scenes->first(fn (AdstoryScene $scene) => strcasecmp((string) $scene->title, $sceneTitle) === 0);

            return $scene?->id;
        }

        return null;
    }
}
