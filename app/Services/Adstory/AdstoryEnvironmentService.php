<?php

namespace App\Services\Adstory;

use App\Models\AdstoryEnvironment;
use App\Models\AdstoryProject;
use Illuminate\Support\Facades\DB;

class AdstoryEnvironmentService
{
    /**
     * @param  array<int, array<string, mixed>>  $environmentsData
     * @return array<int, array<string, mixed>>
     */
    public function upsertProjectEnvironments(AdstoryProject $project, array $environmentsData): array
    {
        return DB::transaction(function () use ($project, $environmentsData) {
            $savedEnvironments = [];
            $orderIndex = (int) ($project->environments()->max('order_index') ?? -1);

            foreach ($environmentsData as $environmentData) {
                $existing = $this->findProjectEnvironment($project, [
                    'id' => $environmentData['id'] ?? null,
                    'name' => $environmentData['name'] ?? null,
                ]);

                if ($existing) {
                    $attributes = $this->mapEnvironmentAttributes(
                        projectId: $project->id,
                        data: $environmentData,
                        orderIndex: $existing->order_index,
                    );
                    unset($attributes['adstory_project_id']);
                    $existing->fill($attributes);
                    $existing->save();
                    $savedEnvironments[] = $existing->fresh()->toApiArray();

                    continue;
                }

                $orderIndex++;
                $environment = AdstoryEnvironment::query()->create(
                    $this->mapEnvironmentAttributes(
                        projectId: $project->id,
                        data: array_merge($environmentData, [
                            'image_status' => AdstoryEnvironmentGenerationService::IMAGE_STATUS_PENDING,
                            'status' => 'draft',
                        ]),
                        orderIndex: $orderIndex,
                        visualStyle: $project->visual_style,
                    )
                );

                $savedEnvironments[] = $environment->toApiArray();
            }

            $project->current_step = 'environments';
            $project->save();

            return $savedEnvironments;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $environmentsData
     * @return array<int, array<string, mixed>>
     */
    public function replaceProjectEnvironments(AdstoryProject $project, array $environmentsData): array
    {
        return DB::transaction(function () use ($project, $environmentsData) {
            $project->environments()->delete();

            $savedEnvironments = [];

            foreach ($environmentsData as $index => $environmentData) {
                $environment = AdstoryEnvironment::query()->create(
                    $this->mapEnvironmentAttributes(
                        projectId: $project->id,
                        data: $environmentData,
                        orderIndex: $index,
                    )
                );

                $savedEnvironments[] = $environment->toApiArray();
            }

            $project->current_step = 'environments';
            $project->save();

            return $savedEnvironments;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mapEnvironmentAttributes(int $projectId, array $data, int $orderIndex, ?string $visualStyle = null): array
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        if (! empty($data['id']) && ! is_numeric($data['id'])) {
            $meta['legacy_id'] = (string) $data['id'];
        }

        if (array_key_exists('importance', $data)) {
            $meta['importance'] = $data['importance'];
        }

        $references = $data['references'] ?? null;
        if (is_array($references)) {
            $references = $this->normalizeReferenceItems($references);
        }

        $locationType = $data['location_type'] ?? $data['type'] ?? null;

        return [
            'adstory_project_id' => $projectId,
            'name' => $data['name'] ?? null,
            'type' => $data['type'] ?? $locationType,
            'location_type' => $locationType,
            'time_of_day' => $data['time_of_day'] ?? null,
            'description' => $data['description'] ?? null,
            'appearance' => $data['appearance'] ?? $data['description'] ?? null,
            'lighting' => $data['lighting'] ?? null,
            'mood' => $data['mood'] ?? null,
            'visual_style' => $data['visual_style'] ?? $visualStyle,
            'image_url' => $data['image_url'] ?? null,
            'image_status' => $data['image_status'] ?? AdstoryEnvironmentGenerationService::IMAGE_STATUS_PENDING,
            'prompt' => $data['prompt'] ?? null,
            'generation_error' => $data['generation_error'] ?? null,
            'references' => $references === [] ? null : $references,
            'order_index' => $data['order_index'] ?? $orderIndex,
            'status' => $data['status'] ?? 'draft',
            'meta' => $meta === [] ? null : $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $lookup
     */
    public function findProjectEnvironment(AdstoryProject $project, array $lookup): ?AdstoryEnvironment
    {
        if (! empty($lookup['environment_id'])) {
            $environment = $project->environments()->where('id', (int) $lookup['environment_id'])->first();
            if ($environment) {
                return $environment;
            }
        }

        $legacyId = $lookup['id'] ?? null;
        if ($legacyId !== null && $legacyId !== '') {
            if (is_numeric($legacyId)) {
                $environment = $project->environments()->where('id', (int) $legacyId)->first();
                if ($environment) {
                    return $environment;
                }
            }

            $environment = $project->environments()
                ->get()
                ->first(function (AdstoryEnvironment $environment) use ($legacyId) {
                    $meta = $environment->meta ?? [];
                    $storedId = $meta['legacy_id'] ?? $meta['id'] ?? null;

                    return $storedId === (string) $legacyId;
                });

            if ($environment) {
                return $environment;
            }
        }

        $name = $lookup['name'] ?? null;
        if (is_string($name) && $name !== '') {
            $environment = $project->environments()
                ->get()
                ->first(fn (AdstoryEnvironment $environment) => strcasecmp((string) $environment->name, $name) === 0);

            if ($environment) {
                return $environment;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $references
     * @return array<int, array<string, mixed>>
     */
    private function normalizeReferenceItems(array $references): array
    {
        return array_values(array_map(function (array $reference) {
            $type = $reference['type'] ?? $reference['reference_type'] ?? 'hero';

            return [
                'type' => $type,
                'reference_type' => $type,
                'title' => $reference['title'] ?? null,
                'image_url' => $reference['image_url'] ?? null,
                'prompt' => $reference['prompt'] ?? null,
                'created_at' => $reference['created_at'] ?? null,
                'status' => $reference['status'] ?? 'pending',
            ];
        }, $references));
    }
}
