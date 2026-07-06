<?php

namespace App\Services\Adstory;

use App\Models\AdstoryCharacter;
use App\Models\AdstoryCharacterAsset;
use App\Models\AdstoryEnvironment;
use App\Models\AdstoryEnvironmentAsset;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use App\Models\AdstoryShotImage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdstoryShotImageService
{
    /**
     * @return array{prompt: string, included: array<string, mixed>}
     */
    public function buildShotImagePrompt(
        AdstoryShot $shot,
        ?AdstoryScene $scene,
        AdstoryProject $project,
        array $characters,
        ?AdstoryEnvironment $environment,
        ?string $customPrompt = null,
    ): array {
        if ($customPrompt !== null && trim($customPrompt) !== '') {
            return [
                'prompt' => trim($customPrompt),
                'included' => ['custom_prompt' => true],
            ];
        }

        if (! $this->hasStoryboardSettings($shot) && ! empty($shot->prompt)) {
            return [
                'prompt' => trim((string) $shot->prompt),
                'included' => ['shot_prompt' => true],
            ];
        }

        return $this->buildStoryboardAwarePrompt(
            shot: $shot,
            scene: $scene,
            project: $project,
            characters: $characters,
            environment: $environment,
        );
    }

    public function hasStoryboardSettings(AdstoryShot $shot): bool
    {
        return $this->hasPresetData($shot->composition_preset)
            || $this->hasPresetData($shot->cinematography_preset)
            || $this->hasPresetData($shot->lighting_preset)
            || $this->hasPresetData($shot->storyboard_settings)
            || $this->hasSelectedAssets($shot->selected_character_assets)
            || $this->hasSelectedAssets($shot->selected_environment_assets);
    }

    /**
     * @param  array<int, AdstoryCharacter>  $characters
     * @return array{prompt: string, included: array<string, mixed>}
     */
    private function buildStoryboardAwarePrompt(
        AdstoryShot $shot,
        ?AdstoryScene $scene,
        AdstoryProject $project,
        array $characters,
        ?AdstoryEnvironment $environment,
    ): array {
        $included = [
            'composition_preset' => false,
            'cinematography_preset' => false,
            'lighting_preset' => false,
            'storyboard_settings' => false,
            'character_asset_ids' => [],
            'environment_asset_ids' => [],
        ];

        $visualStyle = $project->visual_style ?? 'Cinematic Realistic';
        $compositionPreset = $shot->composition_preset ?? null;
        $cinematographyPreset = $shot->cinematography_preset ?? null;
        $lightingPreset = $shot->lighting_preset ?? null;
        $storyboardSettings = $shot->storyboard_settings ?? null;

        $title = $shot->title ?? 'Untitled shot';
        $description = $shot->description ?? '';
        $action = $shot->action ?? '';
        $dialogue = $shot->dialogue ?? '';
        $shotSize = $this->presetValue($cinematographyPreset, 'shot_size', $shot->shot_size ?? 'Medium shot');
        $cameraAngle = $this->presetValue($cinematographyPreset, 'camera_angle', $shot->camera_angle ?? 'Eye level');
        $cameraMovement = $this->presetValue($cinematographyPreset, 'camera_movement', $shot->camera_movement ?? 'Static');
        $composition = $this->presetValue($compositionPreset, 'composition', $shot->composition ?? 'Rule of thirds');
        $lens = $this->presetValue($cinematographyPreset, 'lens', $shot->lens ?? 'Standard cinematic lens');
        $lighting = $this->presetValue($lightingPreset, 'lighting', $shot->lighting ?? 'Natural cinematic lighting');
        $mood = $shot->meta['mood'] ?? $scene?->mood ?? 'Neutral';
        $duration = $shot->duration_seconds ?? 3;
        $shotEnvironment = $shot->environment ?? '';
        $shotCharacters = ! empty($shot->characters) ? implode(', ', $shot->characters) : 'None';

        if ($this->hasPresetData($compositionPreset)) {
            $included['composition_preset'] = true;
        }

        if ($this->hasPresetData($cinematographyPreset)) {
            $included['cinematography_preset'] = true;
        }

        if ($this->hasPresetData($lightingPreset)) {
            $included['lighting_preset'] = true;
        }

        if ($this->hasPresetData($storyboardSettings)) {
            $included['storyboard_settings'] = true;
        }

        $sceneContext = '';
        if ($scene) {
            $sceneVisualStyle = $scene->visual_style ?? $visualStyle;
            $sceneContext = <<<SCENE

Scene context:
- Scene title: {$scene->title}
- Scene description: {$scene->description}
- Location: {$scene->location}
- Time of day: {$scene->time_of_day}
- Scene mood: {$scene->mood}
- Scene visual style: {$sceneVisualStyle}
SCENE;
        }

        $characterAssetLines = '';
        $selectedCharacterAssets = $this->resolveSelectedCharacterAssets($shot, $project);
        if ($selectedCharacterAssets !== []) {
            $included['character_asset_ids'] = array_map(fn (AdstoryCharacterAsset $a) => $a->id, $selectedCharacterAssets);
            $characterAssetLines = "\nSelected character references (preserve identity and appearance):\n";
            foreach ($selectedCharacterAssets as $asset) {
                $characterName = $asset->character?->name ?? 'Unknown character';
                $characterAssetLines .= "- Character: {$characterName}\n";
                $characterAssetLines .= "  Asset type: {$asset->asset_type}\n";
                $characterAssetLines .= "  Title: {$asset->title}\n";
                if ($asset->image_url) {
                    $characterAssetLines .= "  Reference image URL: {$asset->image_url}\n";
                }
            }
        } elseif ($characters !== []) {
            $characterAssetLines = "\nCharacters in shot:\n";
            foreach ($characters as $character) {
                $appearance = $character->appearance ?? $character->description ?? '';
                $characterAssetLines .= "- {$character->name}: {$appearance}\n";
                if ($character->image_url) {
                    $characterAssetLines .= "  Character reference image: {$character->image_url}\n";
                }
            }
        } elseif ($shotCharacters !== 'None') {
            $characterAssetLines = "\nCharacters in shot: {$shotCharacters}\n";
        }

        $environmentAssetLines = '';
        $selectedEnvironmentAssets = $this->resolveSelectedEnvironmentAssets($shot, $project);
        if ($selectedEnvironmentAssets !== []) {
            $included['environment_asset_ids'] = array_map(fn (AdstoryEnvironmentAsset $a) => $a->id, $selectedEnvironmentAssets);
            $environmentAssetLines = "\nSelected environment references (preserve layout, mood, and visual consistency):\n";
            foreach ($selectedEnvironmentAssets as $asset) {
                $environmentName = $asset->environment?->name ?? 'Unknown environment';
                $environmentAssetLines .= "- Environment: {$environmentName}\n";
                $environmentAssetLines .= "  Asset type: {$asset->asset_type}\n";
                $environmentAssetLines .= "  Title: {$asset->title}\n";
                if ($asset->image_url) {
                    $environmentAssetLines .= "  Reference image URL: {$asset->image_url}\n";
                }
            }
        } elseif ($environment) {
            $environmentAssetLines = <<<ENV

Environment:
- Name: {$environment->name}
- Type: {$environment->type}
- Time of day: {$environment->time_of_day}
- Description: {$environment->description}
- Mood: {$environment->mood}
ENV;
            if ($environment->image_url) {
                $environmentAssetLines .= "- Environment reference image: {$environment->image_url}\n";
            }
        } elseif ($shotEnvironment !== '') {
            $environmentAssetLines = "\nEnvironment: {$shotEnvironment}\n";
        }

        $compositionSection = $this->hasPresetData($compositionPreset)
            ? "\nSelected composition preset:\n".$this->formatPresetBlock($compositionPreset)
            : '';

        $cinematographySection = $this->hasPresetData($cinematographyPreset)
            ? "\nSelected cinematography preset:\n".$this->formatPresetBlock($cinematographyPreset)
            : '';

        $lightingSection = $this->hasPresetData($lightingPreset)
            ? "\nSelected lighting preset:\n".$this->formatPresetBlock($lightingPreset)
            : '';

        $storyboardSection = $this->hasPresetData($storyboardSettings)
            ? "\nAdditional storyboard settings:\n".$this->formatPresetBlock($storyboardSettings)
            : '';

        $compositionRule = $included['composition_preset']
            ? '- The generated image must match the selected composition preset.'
            : '- Use cinematic composition matching the shot metadata.';

        $cinematographyRule = $included['cinematography_preset']
            ? '- The generated image must match the selected cinematography preset.'
            : '';

        $lightingRule = $included['lighting_preset']
            ? '- The generated image must match the selected lighting preset.'
            : '';

        $characterRule = $included['character_asset_ids'] !== []
            ? '- Keep character identity consistent with the selected character references.'
            : '';

        $environmentRule = $included['environment_asset_ids'] !== []
            ? '- Keep environment consistent with the selected environment references.'
            : '';

        $prompt = <<<PROMPT
Generate a cinematic storyboard frame image for film pre-production.

Project visual style: {$visualStyle}

Shot details:
- Title: {$title}
- Description: {$description}
- Action: {$action}
- Dialogue: {$dialogue}
- Shot size: {$shotSize}
- Camera angle: {$cameraAngle}
- Camera movement: {$cameraMovement}
- Composition: {$composition}
- Lens: {$lens}
- Lighting: {$lighting}
- Environment: {$shotEnvironment}
- Characters: {$shotCharacters}
- Mood: {$mood}
- Duration: {$duration} seconds
{$sceneContext}
{$compositionSection}
{$cinematographySection}
{$lightingSection}
{$storyboardSection}
{$characterAssetLines}
{$environmentAssetLines}

Rules:
- Single storyboard frame only.
- Clear subject focus and readable staging.
{$compositionRule}
{$cinematographyRule}
{$lightingRule}
{$characterRule}
{$environmentRule}
- No text overlays.
- No watermarks.
- No UI elements.
- No distorted faces.
- No extra limbs or duplicated body parts.
- Match the project visual style, mood, and lighting described above.
PROMPT;

        return [
            'prompt' => trim($prompt),
            'included' => $included,
        ];
    }

    /**
     * @return array<int, AdstoryCharacterAsset>
     */
    public function resolveSelectedCharacterAssets(AdstoryShot $shot, AdstoryProject $project): array
    {
        $assetIds = $this->extractAssetIds($shot->selected_character_assets);

        if ($assetIds->isEmpty()) {
            return [];
        }

        return AdstoryCharacterAsset::query()
            ->where('adstory_project_id', $project->id)
            ->whereIn('id', $assetIds)
            ->with('character')
            ->get()
            ->all();
    }

    /**
     * @return array<int, AdstoryEnvironmentAsset>
     */
    public function resolveSelectedEnvironmentAssets(AdstoryShot $shot, AdstoryProject $project): array
    {
        $assetIds = $this->extractAssetIds($shot->selected_environment_assets);

        if ($assetIds->isEmpty()) {
            return [];
        }

        return AdstoryEnvironmentAsset::query()
            ->where('adstory_project_id', $project->id)
            ->whereIn('id', $assetIds)
            ->with('environment')
            ->get()
            ->all();
    }

    /**
     * @return array<int, AdstoryCharacter>
     */
    public function resolveCharactersForShot(AdstoryShot $shot, AdstoryProject $project): array
    {
        $shotCharacterNames = collect($shot->characters ?? [])
            ->map(fn ($name) => is_string($name) ? trim($name) : '')
            ->filter()
            ->values();

        if ($shotCharacterNames->isEmpty()) {
            return [];
        }

        $projectCharacters = $project->characters;

        return $shotCharacterNames
            ->map(function (string $name) use ($projectCharacters) {
                return $projectCharacters->first(function (AdstoryCharacter $character) use ($name) {
                    return strcasecmp((string) $character->name, $name) === 0;
                });
            })
            ->filter()
            ->values()
            ->all();
    }

    public function resolveEnvironmentForShot(
        AdstoryShot $shot,
        ?AdstoryScene $scene,
        AdstoryProject $project,
    ): ?AdstoryEnvironment {
        $candidates = array_filter([
            is_string($shot->environment) ? trim($shot->environment) : null,
            is_string($scene?->environment) ? trim($scene->environment) : null,
            is_string($scene?->location) ? trim($scene->location) : null,
        ]);

        foreach ($candidates as $candidate) {
            $match = $project->environments->first(function (AdstoryEnvironment $environment) use ($candidate) {
                if (strcasecmp((string) $environment->name, $candidate) === 0) {
                    return true;
                }

                $description = (string) ($environment->description ?? '');

                return $description !== '' && str_contains(strtolower($description), strtolower($candidate));
            });

            if ($match) {
                return $match;
            }
        }

        return null;
    }

    public function shouldSkipShot(AdstoryShot $shot, bool $regenerate): bool
    {
        if ($regenerate) {
            return false;
        }

        if ($shot->approvedImage()->exists()) {
            return true;
        }

        return ! empty($shot->image_url) && $shot->image_status === 'completed';
    }

    public function nextVersionNumber(int $shotId): int
    {
        $latest = AdstoryShotImage::query()
            ->where('adstory_shot_id', $shotId)
            ->max('version_number');

        return ((int) $latest) + 1;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function createVersion(
        AdstoryShot $shot,
        AdstoryProject $project,
        ?AdstoryScene $scene,
        string $imageUrl,
        string $storagePath,
        string $prompt,
        array $meta = [],
    ): AdstoryShotImage {
        return DB::transaction(function () use ($shot, $project, $scene, $imageUrl, $storagePath, $prompt, $meta) {
            $versionNumber = $this->nextVersionNumber($shot->id);

            $image = AdstoryShotImage::query()->create([
                'adstory_project_id' => $project->id,
                'adstory_scene_id' => $scene?->id ?? $shot->adstory_scene_id,
                'adstory_shot_id' => $shot->id,
                'version_number' => $versionNumber,
                'title' => $shot->title,
                'prompt' => $prompt,
                'image_url' => $imageUrl,
                'storage_path' => $storagePath,
                'status' => 'completed',
                'is_approved' => false,
                'meta' => $meta === [] ? null : $meta,
            ]);

            $shot->image_url = $imageUrl;
            $shot->image_status = 'completed';
            $shot->prompt = $prompt;
            $shot->save();

            return $image;
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function createFailedVersion(
        AdstoryShot $shot,
        AdstoryProject $project,
        ?AdstoryScene $scene,
        string $prompt,
        string $errorMessage,
        array $meta = [],
    ): AdstoryShotImage {
        $versionNumber = $this->nextVersionNumber($shot->id);

        return AdstoryShotImage::query()->create([
            'adstory_project_id' => $project->id,
            'adstory_scene_id' => $scene?->id ?? $shot->adstory_scene_id,
            'adstory_shot_id' => $shot->id,
            'version_number' => $versionNumber,
            'title' => $shot->title,
            'prompt' => $prompt,
            'status' => 'failed',
            'is_approved' => false,
            'meta' => array_merge($meta, ['error' => $errorMessage]),
        ]);
    }

    public function approveImage(AdstoryShotImage $image): AdstoryShot
    {
        return DB::transaction(function () use ($image) {
            AdstoryShotImage::query()
                ->where('adstory_shot_id', $image->adstory_shot_id)
                ->update(['is_approved' => false]);

            $image->is_approved = true;
            $image->save();

            /** @var AdstoryShot $shot */
            $shot = $image->shot()->firstOrFail();
            $shot->image_url = $image->image_url;
            $shot->image_status = 'completed';
            $shot->prompt = $image->prompt;
            $shot->save();

            return $shot->fresh(['scene', 'shotImages', 'approvedImage']);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function imagesForShot(AdstoryShot $shot): array
    {
        return $shot->shotImages()
            ->orderBy('version_number')
            ->get()
            ->map(fn (AdstoryShotImage $image) => $image->toApiArray())
            ->values()
            ->all();
    }

    public function shotBelongsToProject(AdstoryShot $shot, AdstoryProject $project): bool
    {
        return $shot->adstory_project_id === $project->id;
    }

    public function imageBelongsToShotAndProject(
        AdstoryShotImage $image,
        AdstoryShot $shot,
        AdstoryProject $project,
    ): bool {
        return $image->adstory_shot_id === $shot->id
            && $image->adstory_project_id === $project->id
            && $shot->adstory_project_id === $project->id;
    }

    /**
     * @param  mixed  $selected
     */
    private function extractAssetIds(mixed $selected): Collection
    {
        if (! is_array($selected) || $selected === []) {
            return collect();
        }

        return collect($selected)
            ->map(function ($item) {
                if (is_numeric($item)) {
                    return (int) $item;
                }

                if (is_array($item)) {
                    return (int) ($item['id'] ?? $item['asset_id'] ?? $item['db_id'] ?? 0);
                }

                return 0;
            })
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();
    }

    /**
     * @param  mixed  $preset
     */
    private function hasPresetData(mixed $preset): bool
    {
        if (! is_array($preset) || $preset === []) {
            return false;
        }

        return collect($preset)
            ->filter(fn ($value) => $value !== null && $value !== '' && $value !== [])
            ->isNotEmpty();
    }

    /**
     * @param  mixed  $selected
     */
    private function hasSelectedAssets(mixed $selected): bool
    {
        return $this->extractAssetIds($selected)->isNotEmpty();
    }

    /**
     * @param  array<string, mixed>|null  $preset
     */
    private function presetValue(?array $preset, string $key, string $fallback): string
    {
        if (! is_array($preset)) {
            return $fallback;
        }

        $value = $preset[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $preset
     */
    private function formatPresetBlock(array $preset): string
    {
        $lines = [];

        foreach ($preset as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $label = ucwords(str_replace('_', ' ', (string) $key));
            $formattedValue = is_array($value) ? json_encode($value) : (string) $value;
            $lines[] = "- {$label}: {$formattedValue}";
        }

        return implode("\n", $lines);
    }
}
