<?php

namespace App\Services\Adstory;

use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdstorySceneService
{
    /**
     * Persist completed status immediately — never rely on fill()/defaults alone.
     */
    public function markSceneCompleted(AdstoryScene $scene, ?Carbon $generatedAt = null): void
    {
        $timestamp = $generatedAt ?? now();

        AdstoryScene::query()
            ->where('id', $scene->id)
            ->update([
                'status' => AdstorySceneGenerationService::SCENE_STATUS_COMPLETED,
                'generated_at' => $timestamp,
                'generation_error' => null,
            ]);

        Log::info("Scene {$scene->id} marked completed.", [
            'scene_id' => $scene->id,
            'project_id' => $scene->adstory_project_id,
            'generated_at' => $timestamp->toIso8601String(),
        ]);
    }

    /**
     * Persist failed status immediately.
     */
    public function markSceneFailed(AdstoryScene $scene, string $message): void
    {
        AdstoryScene::query()
            ->where('id', $scene->id)
            ->update([
                'status' => AdstorySceneGenerationService::SCENE_STATUS_FAILED,
                'generation_error' => $message,
            ]);

        Log::info("Scene {$scene->id} marked failed.", [
            'scene_id' => $scene->id,
            'project_id' => $scene->adstory_project_id,
            'error' => $message,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenesData
     * @return array<int, array<string, mixed>>
     */
    public function replaceProjectScenes(
        AdstoryProject $project,
        array $scenesData,
        ?string $visualStyle = null,
        bool $force = false,
    ): array {
        return DB::transaction(function () use ($project, $scenesData, $visualStyle, $force) {
            $existingById = $project->scenes()->get()->keyBy('id');
            $savedScenes = [];
            $keptIds = [];

            foreach ($scenesData as $index => $sceneData) {
                $sceneId = isset($sceneData['id']) ? (int) $sceneData['id'] : null;
                $existing = ($sceneId !== null && $existingById->has($sceneId))
                    ? $existingById->get($sceneId)
                    : null;

                $attributes = $this->mapSceneAttributes(
                    projectId: $project->id,
                    data: $sceneData,
                    orderIndex: $index,
                    visualStyle: $visualStyle ?? $project->visual_style,
                    existing: $existing,
                    force: $force,
                );

                if ($existing) {
                    if ($force) {
                        $existing->allowStatusDowngrade = true;
                    }

                    $existing->fill($attributes);
                    $existing->save();
                    $scene = $existing->fresh();
                } else {
                    $scene = AdstoryScene::query()->create($attributes);
                }

                $keptIds[] = $scene->id;
                $savedScenes[] = $scene->toApiArray();
            }

            if ($keptIds !== []) {
                $project->scenes()->whereNotIn('id', $keptIds)->delete();
            }

            $project->current_step = 'scenes';
            $project->save();

            return $savedScenes;
        });
    }

    /**
     * Reorder scene_number (1-based) and order_index (0-based) for all project scenes.
     */
    public function renumberProjectScenes(int $projectId): void
    {
        $scenes = AdstoryScene::query()
            ->where('adstory_project_id', $projectId)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        foreach ($scenes as $index => $scene) {
            $sceneNumber = $index + 1;
            $orderIndex = $index;

            if ((int) $scene->scene_number === $sceneNumber && (int) $scene->order_index === $orderIndex) {
                continue;
            }

            AdstoryScene::query()
                ->where('id', $scene->id)
                ->update([
                    'scene_number' => $sceneNumber,
                    'order_index' => $orderIndex,
                ]);
        }

        Log::info('Adstory scenes: renumbered project scenes', [
            'project_id' => $projectId,
            'scene_count' => $scenes->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{scene: AdstoryScene, warning: string|null}
     */
    public function updateSceneboardScene(AdstoryProject $project, AdstoryScene $scene, array $data): array
    {
        if ($scene->adstory_project_id !== $project->id) {
            throw new \RuntimeException('Scene not found for this project.');
        }

        $hasShots = $scene->shots()->exists();
        $warning = $hasShots
            ? 'This scene already has shots. Editing may make shots inconsistent.'
            : null;

        $meta = is_array($scene->meta ?? null) ? $scene->meta : [];

        if (array_key_exists('meta', $data) && is_array($data['meta'])) {
            $meta = array_merge($meta, $data['meta']);
        }

        if (array_key_exists('environment', $data)) {
            $meta['environment'] = $data['environment'];
        }

        $updates = [];

        foreach (['title', 'description', 'location', 'time_of_day', 'mood', 'visual_style', 'environment'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }

        if (array_key_exists('title', $data) && ! empty($data['title'])) {
            $updates['slug'] = Str::slug((string) $data['title']);
        }

        if ($meta !== []) {
            $updates['meta'] = $meta;
        }

        if ($updates !== []) {
            AdstoryScene::query()->where('id', $scene->id)->update($updates);
        }

        return [
            'scene' => $scene->fresh(),
            'warning' => $warning,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function insertSceneboardScene(
        AdstoryProject $project,
        array $data,
        string $position,
        ?int $referenceSceneId = null,
    ): AdstoryScene {
        return DB::transaction(function () use ($project, $data, $position, $referenceSceneId) {
            $insertOrderIndex = $this->resolveInsertOrderIndex($project, $position, $referenceSceneId);

            AdstoryScene::query()
                ->where('adstory_project_id', $project->id)
                ->where('order_index', '>=', $insertOrderIndex)
                ->increment('order_index');

            $statusInput = $data['status'] ?? 'completed';
            $status = $statusInput === 'draft'
                ? AdstorySceneGenerationService::SCENE_STATUS_PENDING
                : AdstorySceneGenerationService::SCENE_STATUS_COMPLETED;

            $attributes = $this->mapSceneAttributes(
                projectId: $project->id,
                data: array_merge($data, [
                    'status' => $status,
                    'order_index' => $insertOrderIndex,
                ]),
                orderIndex: $insertOrderIndex,
                visualStyle: $data['visual_style'] ?? $project->visual_style,
            );

            $attributes['shot_generation_status'] = 'not_started';
            $attributes['shot_generation_error'] = null;

            $scene = AdstoryScene::query()->create($attributes);

            $this->renumberProjectScenes($project->id);

            if ($project->current_step !== 'sceneboard' && $project->current_step !== 'studio') {
                $project->update(['current_step' => 'sceneboard']);
            }

            return $scene->fresh();
        });
    }

    public function deleteSceneboardScene(AdstoryProject $project, AdstoryScene $scene, bool $force = false): void
    {
        if ($scene->adstory_project_id !== $project->id) {
            throw new \RuntimeException('Scene not found for this project.');
        }

        $hasShots = $scene->shots()->exists();

        if ($hasShots && ! $force) {
            throw new \RuntimeException('Scene has shots. Pass force=true to delete the scene and its shots.');
        }

        DB::transaction(function () use ($project, $scene, $force) {
            if ($force) {
                $scene->shots()->delete();
            }

            $scene->delete();
            $this->renumberProjectScenes($project->id);
        });

        Log::info('Adstory sceneboard: scene deleted', [
            'project_id' => $project->id,
            'scene_id' => $scene->id,
            'force' => $force,
        ]);
    }

    private function resolveInsertOrderIndex(
        AdstoryProject $project,
        string $position,
        ?int $referenceSceneId,
    ): int {
        if ($position === 'end') {
            $max = AdstoryScene::query()
                ->where('adstory_project_id', $project->id)
                ->max('order_index');

            return $max === null ? 0 : ((int) $max + 1);
        }

        if ($referenceSceneId === null) {
            throw new \RuntimeException('reference_scene_id is required when position is before or after.');
        }

        $reference = AdstoryScene::query()
            ->where('adstory_project_id', $project->id)
            ->where('id', $referenceSceneId)
            ->first();

        if (! $reference) {
            throw new \RuntimeException('Reference scene not found for this project.');
        }

        return $position === 'before'
            ? (int) $reference->order_index
            : (int) $reference->order_index + 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mapSceneAttributes(
        int $projectId,
        array $data,
        int $orderIndex,
        ?string $visualStyle,
        ?AdstoryScene $existing = null,
        bool $force = false,
    ): array {
        $title = isset($data['title']) ? (string) $data['title'] : null;
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        if (array_key_exists('characters', $data)) {
            $meta['characters'] = $data['characters'];
        }

        $environment = array_key_exists('environment', $data)
            ? $data['environment']
            : ($meta['environment'] ?? null);

        $slug = $data['slug'] ?? null;
        if ($slug === null && $title !== null && $title !== '') {
            $slug = Str::slug($title);
        }

        $status = $this->resolveSceneStatus($data, $existing, $force);

        return [
            'adstory_project_id' => $projectId,
            'adstory_episode_id' => $data['adstory_episode_id'] ?? $existing?->adstory_episode_id,
            'scene_number' => $data['scene_number'] ?? ($orderIndex + 1),
            'title' => $title,
            'slug' => $slug,
            'location' => $data['location'] ?? null,
            'environment' => $environment,
            'time_of_day' => $data['time_of_day'] ?? null,
            'description' => $data['description'] ?? null,
            'screenplay_excerpt' => $data['screenplay_excerpt'] ?? null,
            'mood' => $data['mood'] ?? null,
            'visual_style' => $data['visual_style'] ?? $visualStyle,
            'order_index' => $data['order_index'] ?? $orderIndex,
            'status' => $status,
            'meta' => $meta === [] ? null : $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSceneStatus(array $data, ?AdstoryScene $existing, bool $force): string
    {
        $incoming = $data['status'] ?? null;

        if ($existing !== null && $existing->status === AdstorySceneGenerationService::SCENE_STATUS_COMPLETED) {
            $downgradeStatuses = [
                AdstorySceneGenerationService::SCENE_STATUS_PENDING,
                AdstorySceneGenerationService::SCENE_STATUS_QUEUED,
                AdstorySceneGenerationService::SCENE_STATUS_GENERATING,
            ];

            if (! $force && ($incoming === null || in_array($incoming, $downgradeStatuses, true))) {
                return AdstorySceneGenerationService::SCENE_STATUS_COMPLETED;
            }
        }

        if ($incoming !== null) {
            return (string) $incoming;
        }

        return $existing?->status ?? AdstorySceneGenerationService::SCENE_STATUS_PENDING;
    }
}
