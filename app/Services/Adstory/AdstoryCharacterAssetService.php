<?php

namespace App\Services\Adstory;

use App\Models\AdstoryCharacter;
use App\Models\AdstoryCharacterAsset;
use Illuminate\Support\Str;

class AdstoryCharacterAssetService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function createHeroAsset(
        AdstoryCharacter $character,
        string $imageUrl,
        string $storagePath,
        string $prompt,
        array $meta = [],
    ): AdstoryCharacterAsset {
        $this->clearPrimaryHeroAssets($character->id);

        return AdstoryCharacterAsset::query()->create([
            'adstory_project_id' => $character->adstory_project_id,
            'adstory_character_id' => $character->id,
            'asset_type' => 'hero',
            'title' => 'Hero Image',
            'image_url' => $imageUrl,
            'storage_path' => $storagePath,
            'prompt' => $prompt,
            'is_primary' => true,
            'status' => 'completed',
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function createReferenceAsset(
        AdstoryCharacter $character,
        string $assetType,
        string $title,
        string $imageUrl,
        string $storagePath,
        string $prompt,
        array $meta = [],
    ): AdstoryCharacterAsset {
        return AdstoryCharacterAsset::query()->create([
            'adstory_project_id' => $character->adstory_project_id,
            'adstory_character_id' => $character->id,
            'asset_type' => $assetType,
            'title' => $title,
            'image_url' => $imageUrl,
            'storage_path' => $storagePath,
            'prompt' => $prompt,
            'is_primary' => false,
            'status' => 'completed',
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    public function mapReferenceTypeToAssetType(string $referenceType): string
    {
        $normalized = Str::slug($referenceType, '_');

        return match ($normalized) {
            'front_view', 'front' => 'front',
            'back_view', 'back' => 'back',
            'left_profile', 'left' => 'left',
            'right_profile', 'right' => 'right',
            'standing_full_body', 'full_body' => 'full_body',
            'closeup', 'close_up' => 'closeup',
            'wardrobe' => 'wardrobe',
            'expression', 'talking', 'laughing', 'crying', 'thinking' => 'expression',
            'pose', 'sitting', 'pointing', 'fighting', 'with_stick', 'looking_up', 'looking_down' => 'pose',
            default => $normalized !== '' ? $normalized : 'pose',
        };
    }

    public function assetTypeTitle(string $assetType): string
    {
        return match ($assetType) {
            'hero' => 'Hero Image',
            'front' => 'Front View',
            'back' => 'Back View',
            'left' => 'Left Profile',
            'right' => 'Right Profile',
            'full_body' => 'Full Body',
            'closeup' => 'Close-up',
            'wardrobe' => 'Wardrobe',
            'expression' => 'Expression',
            'pose' => 'Pose Reference',
            default => Str::title(str_replace('_', ' ', $assetType)),
        };
    }

    public function uniqueStorageSuffix(): string
    {
        return (string) now()->timestamp;
    }

    /**
     * Persist hero image and JSON references into adstory_character_assets when missing.
     */
    public function syncLegacyAssets(AdstoryCharacter $character): void
    {
        if (! empty($character->image_url)) {
            $this->ensureHeroAsset(
                character: $character,
                imageUrl: $character->image_url,
                storagePath: (string) ($character->meta['image_storage_path'] ?? ''),
                prompt: (string) ($character->prompt ?? ''),
            );
        }

        foreach ($character->references ?? [] as $reference) {
            if (! is_array($reference) || empty($reference['image_url'])) {
                continue;
            }

            if ($this->assetExistsForImageUrl($character, (string) $reference['image_url'])) {
                continue;
            }

            $referenceType = (string) ($reference['type'] ?? $reference['reference_type'] ?? 'reference');
            $assetType = $this->mapReferenceTypeToAssetType($referenceType);
            $title = (string) ($reference['title'] ?? $this->assetTypeTitle($assetType));

            $this->createReferenceAsset(
                character: $character,
                assetType: $assetType,
                title: $title,
                imageUrl: (string) $reference['image_url'],
                storagePath: (string) ($reference['storage_path'] ?? ''),
                prompt: (string) ($reference['prompt'] ?? ''),
                meta: [
                    'synced_from' => 'references_json',
                    'reference_type' => $referenceType,
                ],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStoryboardAssets(AdstoryCharacter $character): array
    {
        $this->syncLegacyAssets($character);
        $character->load('assets');

        $dbAssets = $character->assets
            ->map(fn (AdstoryCharacterAsset $asset) => $asset->toStoryboardArray())
            ->values()
            ->all();

        $knownImageUrls = collect($dbAssets)
            ->pluck('image_url')
            ->filter()
            ->map(fn (string $url) => $this->normalizeUrl($url))
            ->all();

        foreach ($this->normalizeReferences($character->references ?? []) as $index => $reference) {
            $imageUrl = $reference['image_url'] ?? null;
            if (! $imageUrl || in_array($this->normalizeUrl($imageUrl), $knownImageUrls, true)) {
                continue;
            }

            $referenceType = (string) ($reference['type'] ?? $reference['reference_type'] ?? 'reference');
            $assetType = $this->mapReferenceTypeToAssetType($referenceType);

            $dbAssets[] = [
                'id' => 'legacy_ref_'.$index,
                'asset_type' => $assetType,
                'title' => $reference['title'] ?? $this->assetTypeTitle($assetType),
                'image_url' => $imageUrl,
                'is_primary' => false,
                'status' => $reference['status'] ?? 'completed',
                'source' => 'references_json',
            ];

            $knownImageUrls[] = $this->normalizeUrl($imageUrl);
        }

        if (! empty($character->image_url)) {
            $heroExists = collect($dbAssets)->contains(
                fn (array $asset) => ($asset['asset_type'] ?? null) === 'hero'
                    || $this->normalizeUrl((string) ($asset['image_url'] ?? '')) === $this->normalizeUrl($character->image_url)
            );

            if (! $heroExists) {
                array_unshift($dbAssets, [
                    'id' => 'legacy_hero',
                    'asset_type' => 'hero',
                    'title' => 'Hero Image',
                    'image_url' => $character->image_url,
                    'is_primary' => true,
                    'status' => $character->image_status ?? 'completed',
                    'source' => 'character_image_url',
                ]);
            }
        }

        return array_values($dbAssets);
    }

    public function ensureHeroAsset(
        AdstoryCharacter $character,
        string $imageUrl,
        string $storagePath = '',
        string $prompt = '',
    ): ?AdstoryCharacterAsset {
        if ($imageUrl === '') {
            return null;
        }

        $existing = $character->assets()
            ->where('asset_type', 'hero')
            ->where('image_url', $imageUrl)
            ->first();

        if ($existing) {
            $this->clearPrimaryHeroAssets($character->id);
            $existing->is_primary = true;
            $existing->save();

            return $existing;
        }

        return $this->createHeroAsset($character, $imageUrl, $storagePath, $prompt);
    }

    /**
     * @param  array<int, array<string, mixed>>  $references
     * @return array<int, array<string, mixed>>
     */
    private function normalizeReferences(array $references): array
    {
        return array_values(array_map(function (array $reference) {
            $type = $reference['type'] ?? $reference['reference_type'] ?? null;

            return [
                'type' => $type,
                'reference_type' => $type,
                'title' => $reference['title'] ?? null,
                'image_url' => $reference['image_url'] ?? null,
                'prompt' => $reference['prompt'] ?? null,
                'status' => $reference['status'] ?? (($reference['image_url'] ?? null) ? 'completed' : 'pending'),
            ];
        }, $references));
    }

    private function assetExistsForImageUrl(AdstoryCharacter $character, string $imageUrl): bool
    {
        if ($imageUrl === '') {
            return false;
        }

        return $character->assets()
            ->where('image_url', $imageUrl)
            ->exists();
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim($url, '/');
    }

    private function clearPrimaryHeroAssets(int $characterId): void
    {
        AdstoryCharacterAsset::query()
            ->where('adstory_character_id', $characterId)
            ->where('asset_type', 'hero')
            ->update(['is_primary' => false]);
    }
}
