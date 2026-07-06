<?php

namespace App\Services\Adstory;

use App\Models\AdstoryEnvironment;
use App\Models\AdstoryEnvironmentAsset;
use Illuminate\Support\Str;

class AdstoryEnvironmentAssetService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function createHeroAsset(
        AdstoryEnvironment $environment,
        string $imageUrl,
        string $storagePath,
        string $prompt,
        array $meta = [],
    ): AdstoryEnvironmentAsset {
        $this->clearPrimaryHeroAssets($environment->id);

        return AdstoryEnvironmentAsset::query()->create([
            'adstory_project_id' => $environment->adstory_project_id,
            'adstory_environment_id' => $environment->id,
            'asset_type' => 'hero',
            'title' => 'Hero Environment',
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
        AdstoryEnvironment $environment,
        string $assetType,
        string $title,
        string $imageUrl,
        string $storagePath,
        string $prompt,
        array $meta = [],
    ): AdstoryEnvironmentAsset {
        return AdstoryEnvironmentAsset::query()->create([
            'adstory_project_id' => $environment->adstory_project_id,
            'adstory_environment_id' => $environment->id,
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

    public function assetTypeTitle(string $assetType): string
    {
        return match ($assetType) {
            'hero' => 'Hero Environment',
            'wide' => 'Wide Establishing View',
            'close' => 'Close View',
            'day' => 'Day Lighting',
            'night' => 'Night Lighting',
            'sunset' => 'Sunset Lighting',
            'rain' => 'Rainy Version',
            'fog' => 'Foggy Version',
            'interior' => 'Interior View',
            'exterior' => 'Exterior View',
            default => Str::title(str_replace('_', ' ', $assetType)),
        };
    }

    public function uniqueStorageSuffix(): string
    {
        return (string) now()->timestamp;
    }

    private function clearPrimaryHeroAssets(int $environmentId): void
    {
        AdstoryEnvironmentAsset::query()
            ->where('adstory_environment_id', $environmentId)
            ->where('asset_type', 'hero')
            ->update(['is_primary' => false]);
    }
}
