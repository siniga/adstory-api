<?php

namespace App\Services\Adstory;

use App\Models\AdstoryCharacter;
use App\Models\AdstoryProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdstoryCharacterService
{
    /**
     * @param  array<int, array<string, mixed>>  $charactersData
     * @return array<int, array<string, mixed>>
     */
    public function upsertProjectCharacters(AdstoryProject $project, array $charactersData): array
    {
        return DB::transaction(function () use ($project, $charactersData) {
            $savedCharacters = [];
            $orderIndex = (int) ($project->characters()->max('order_index') ?? -1);

            foreach ($charactersData as $characterData) {
                $existing = $this->findProjectCharacter($project, [
                    'id' => $characterData['id'] ?? null,
                    'name' => $characterData['name'] ?? null,
                ]);

                if ($existing) {
                    $attributes = $this->mapCharacterAttributes(
                        projectId: $project->id,
                        data: $characterData,
                        orderIndex: $existing->order_index,
                    );
                    unset($attributes['adstory_project_id']);
                    $existing->fill($attributes);
                    $existing->save();
                    $savedCharacters[] = $existing->fresh()->toApiArray();

                    continue;
                }

                $orderIndex++;
                $characterData['image_status'] = $characterData['image_status'] ?? 'queued';

                $character = AdstoryCharacter::query()->create(
                    $this->mapCharacterAttributes(
                        projectId: $project->id,
                        data: $characterData,
                        orderIndex: $orderIndex,
                    )
                );

                $savedCharacters[] = $character->toApiArray();
            }

            $project->current_step = 'characters';
            $project->save();

            return $savedCharacters;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $charactersData
     * @return array<int, array<string, mixed>>
     */
    public function replaceProjectCharacters(AdstoryProject $project, array $charactersData): array
    {
        return DB::transaction(function () use ($project, $charactersData) {
            $project->characters()->delete();

            $savedCharacters = [];

            foreach ($charactersData as $index => $characterData) {
                $character = AdstoryCharacter::query()->create(
                    $this->mapCharacterAttributes(
                        projectId: $project->id,
                        data: $characterData,
                        orderIndex: $index,
                    )
                );

                $savedCharacters[] = $character->toApiArray();
            }

            $project->current_step = 'characters';
            $project->save();

            return $savedCharacters;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mapCharacterAttributes(int $projectId, array $data, int $orderIndex): array
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

        return [
            'adstory_project_id' => $projectId,
            'name' => $data['name'] ?? null,
            'role' => $data['role'] ?? null,
            'description' => $data['description'] ?? null,
            'personality' => $data['personality'] ?? null,
            'appearance' => $data['appearance'] ?? $data['description'] ?? null,
            'wardrobe' => $data['wardrobe'] ?? null,
            'age' => isset($data['age']) ? (string) $data['age'] : null,
            'gender' => $data['gender'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'image_status' => $data['image_status'] ?? 'pending',
            'prompt' => $data['prompt'] ?? null,
            'references' => $references === [] ? null : $references,
            'order_index' => $data['order_index'] ?? $orderIndex,
            'status' => $data['status'] ?? 'draft',
            'meta' => $meta === [] ? null : $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $lookup
     */
    public function findProjectCharacter(AdstoryProject $project, array $lookup): ?AdstoryCharacter
    {
        if (! empty($lookup['character_id'])) {
            $character = $project->characters()->where('id', (int) $lookup['character_id'])->first();
            if ($character) {
                return $character;
            }
        }

        $legacyId = $lookup['id'] ?? null;
        if ($legacyId !== null && $legacyId !== '') {
            if (is_numeric($legacyId)) {
                $character = $project->characters()->where('id', (int) $legacyId)->first();
                if ($character) {
                    return $character;
                }
            }

            $character = $project->characters()
                ->get()
                ->first(function (AdstoryCharacter $character) use ($legacyId) {
                    $meta = $character->meta ?? [];
                    $storedId = $meta['legacy_id'] ?? $meta['id'] ?? null;

                    return $storedId === (string) $legacyId;
                });

            if ($character) {
                return $character;
            }
        }

        $name = $lookup['name'] ?? null;
        if (is_string($name) && $name !== '') {
            $character = $project->characters()
                ->get()
                ->first(fn (AdstoryCharacter $character) => strcasecmp((string) $character->name, $name) === 0);

            if ($character) {
                return $character;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $referenceItem
     */
    public function appendReference(AdstoryCharacter $character, array $referenceItem): AdstoryCharacter
    {
        $type = $referenceItem['type'] ?? $referenceItem['reference_type'] ?? null;

        if (! is_string($type) || $type === '') {
            return $character;
        }

        $normalized = [
            'type' => $type,
            'reference_type' => $type,
            'title' => $referenceItem['title'] ?? $this->referenceTypeTitle($type),
            'image_url' => $referenceItem['image_url'] ?? null,
            'prompt' => $referenceItem['prompt'] ?? null,
            'created_at' => $referenceItem['created_at'] ?? now()->toIso8601String(),
            'status' => $referenceItem['status'] ?? 'completed',
        ];

        $references = array_values(array_filter(
            $character->references ?? [],
            fn (array $reference) => ($reference['type'] ?? $reference['reference_type'] ?? null) !== $type
        ));

        $references[] = $normalized;
        $character->references = $references;
        $character->save();

        return $character->fresh();
    }

    public function referenceTypeTitle(string $type): string
    {
        return match ($type) {
            'front_view' => 'Front View',
            'back_view' => 'Back View',
            'left_profile' => 'Left Profile',
            'right_profile' => 'Right Profile',
            'standing_full_body' => 'Standing Full Body',
            'sitting' => 'Sitting',
            'with_stick' => 'With Stick',
            'talking' => 'Talking / Explaining',
            'pointing' => 'Pointing',
            'looking_up' => 'Looking Up',
            'looking_down' => 'Looking Down',
            'laughing' => 'Laughing',
            'crying' => 'Crying / Sad',
            'fighting' => 'Fighting / Angry',
            'thinking' => 'Thinking',
            default => Str::title(str_replace('_', ' ', $type)),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $references
     * @return array<int, array<string, mixed>>
     */
    private function normalizeReferenceItems(array $references): array
    {
        return array_values(array_map(function (array $reference) {
            $type = $reference['type'] ?? $reference['reference_type'] ?? null;

            return [
                'type' => $type,
                'reference_type' => $type,
                'title' => $reference['title'] ?? (is_string($type) ? $this->referenceTypeTitle($type) : null),
                'image_url' => $reference['image_url'] ?? null,
                'prompt' => $reference['prompt'] ?? null,
                'created_at' => $reference['created_at'] ?? null,
                'status' => $reference['status'] ?? 'pending',
            ];
        }, $references));
    }
}
