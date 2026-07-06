<?php

namespace App\Services\Adstory;

use App\Models\AdstoryCharacterAsset;
use App\Models\AdstoryEnvironmentAsset;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use RuntimeException;

class AdstoryShotDirectorService
{
    public function __construct(
        private readonly AdstoryShotImageService $shotImageService,
    ) {}

    public function buildDirectorPrompt(
        AdstoryProject $project,
        AdstoryShot $shot,
        ?AdstoryScene $scene,
        array $characterAssets,
        array $environmentAssets,
        string $instruction,
    ): string {
        $visualStyle = $project->visual_style ?? 'Cinematic Realistic';
        $storyExcerpt = $this->truncate($project->story ?? '', 1500);
        $screenplayExcerpt = $this->truncate($project->screenplay ?? '', 2000);

        $sceneBlock = $scene
            ? <<<SCENE

Scene:
- Title: {$scene->title}
- Description: {$scene->description}
- Location: {$scene->location}
- Mood: {$scene->mood}
- Time of day: {$scene->time_of_day}
SCENE
            : "\nScene: Not linked to a saved scene.\n";

        $characterBlock = $this->formatCharacterAssetsBlock($characterAssets);
        $environmentBlock = $this->formatEnvironmentAssetsBlock($environmentAssets);

        $compositionPreset = $this->formatJsonBlock($shot->composition_preset);
        $cinematographyPreset = $this->formatJsonBlock($shot->cinematography_preset);
        $lightingPreset = $this->formatJsonBlock($shot->lighting_preset);
        $storyboardSettings = $this->formatJsonBlock($shot->storyboard_settings);

        $title = $shot->title ?? 'Untitled shot';
        $description = $shot->description ?? '';
        $action = $shot->action ?? '';
        $dialogue = $shot->dialogue ?? '';
        $shotSize = $shot->shot_size ?? '';
        $cameraAngle = $shot->camera_angle ?? '';
        $cameraMovement = $shot->camera_movement ?? '';
        $composition = $shot->composition ?? '';
        $lens = $shot->lens ?? '';
        $lighting = $shot->lighting ?? '';
        $environment = $shot->environment ?? '';
        $mood = $shot->meta['mood'] ?? '';
        $currentPrompt = $shot->prompt ?? '';

        return <<<PROMPT
You are an experienced film director and cinematography consultant. Analyze the storyboard shot below and suggest improvements. Do not generate an image. Return suggestions only.

Director instruction from the user:
{$instruction}

Project:
- Visual style: {$visualStyle}
- Story excerpt: {$storyExcerpt}
- Screenplay excerpt: {$screenplayExcerpt}
{$sceneBlock}

Current shot:
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
- Environment: {$environment}
- Mood: {$mood}
- Current image prompt: {$currentPrompt}

Current storyboard presets:
- Composition preset: {$compositionPreset}
- Cinematography preset: {$cinematographyPreset}
- Lighting preset: {$lightingPreset}
- Storyboard settings: {$storyboardSettings}
{$characterBlock}
{$environmentBlock}

Rules:
- Respect the project visual style and story context.
- Honor the director instruction while staying faithful to the scene and shot.
- Suggest practical, production-ready improvements for storyboard image generation.
- Keep character identity consistent with selected character references when provided.
- Keep environment consistency with selected environment references when provided.
- Do not invent unrelated plot points.
- Return only valid JSON.
- No markdown.
- No explanation.
- No text before or after JSON.

Return JSON using exactly this schema:

{
  "composition": {
    "name": "Suggested composition approach",
    "reason": "Why this composition works"
  },
  "camera": {
    "shot_size": "Suggested shot size",
    "angle": "Suggested camera angle",
    "movement": "Suggested camera movement",
    "lens": "Suggested lens choice",
    "reason": "Why this camera setup works"
  },
  "lighting": {
    "style": "Suggested lighting style",
    "reason": "Why this lighting works"
  },
  "mood": "Suggested mood",
  "color_palette": "Suggested color palette",
  "notes": "Additional director notes",
  "updated_prompt": "A complete revised storyboard image prompt incorporating your suggestions"
}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseDirectorJson(string $text): array
    {
        $json = $this->extractJsonObject($text);
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new RuntimeException('Failed to parse director suggestions from Gemini response: invalid JSON.');
        }

        return $this->normalizeDirectorResponse($decoded);
    }

    /**
     * @return array<int, AdstoryCharacterAsset>
     */
    public function resolveSelectedCharacterAssets(AdstoryShot $shot, AdstoryProject $project): array
    {
        $selected = $this->shotImageService->resolveSelectedCharacterAssets($shot, $project);

        if ($selected !== []) {
            return $selected;
        }

        return [];
    }

    /**
     * @return array<int, AdstoryEnvironmentAsset>
     */
    public function resolveSelectedEnvironmentAssets(AdstoryShot $shot, AdstoryProject $project): array
    {
        return $this->shotImageService->resolveSelectedEnvironmentAssets($shot, $project);
    }

    /**
     * @param  array<int, AdstoryCharacterAsset>  $assets
     */
    private function formatCharacterAssetsBlock(array $assets): string
    {
        if ($assets === []) {
            return "\nSelected character assets: None selected.\n";
        }

        $lines = ["\nSelected character assets:"];
        foreach ($assets as $asset) {
            $name = $asset->character?->name ?? 'Unknown character';
            $lines[] = "- Character: {$name}";
            $lines[] = "  Asset type: {$asset->asset_type}";
            $lines[] = "  Title: {$asset->title}";
            if ($asset->image_url) {
                $lines[] = "  Reference image URL: {$asset->image_url}";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<int, AdstoryEnvironmentAsset>  $assets
     */
    private function formatEnvironmentAssetsBlock(array $assets): string
    {
        if ($assets === []) {
            return "\nSelected environment assets: None selected.\n";
        }

        $lines = ["\nSelected environment assets:"];
        foreach ($assets as $asset) {
            $name = $asset->environment?->name ?? 'Unknown environment';
            $lines[] = "- Environment: {$name}";
            $lines[] = "  Asset type: {$asset->asset_type}";
            $lines[] = "  Title: {$asset->title}";
            if ($asset->image_url) {
                $lines[] = "  Reference image URL: {$asset->image_url}";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function normalizeDirectorResponse(array $decoded): array
    {
        $composition = is_array($decoded['composition'] ?? null) ? $decoded['composition'] : [];
        $camera = is_array($decoded['camera'] ?? null) ? $decoded['camera'] : [];
        $lighting = is_array($decoded['lighting'] ?? null) ? $decoded['lighting'] : [];

        return [
            'composition' => [
                'name' => (string) ($composition['name'] ?? ''),
                'reason' => (string) ($composition['reason'] ?? ''),
            ],
            'camera' => [
                'shot_size' => (string) ($camera['shot_size'] ?? ''),
                'angle' => (string) ($camera['angle'] ?? ''),
                'movement' => (string) ($camera['movement'] ?? ''),
                'lens' => (string) ($camera['lens'] ?? ''),
                'reason' => (string) ($camera['reason'] ?? ''),
            ],
            'lighting' => [
                'style' => (string) ($lighting['style'] ?? ''),
                'reason' => (string) ($lighting['reason'] ?? ''),
            ],
            'mood' => (string) ($decoded['mood'] ?? ''),
            'color_palette' => (string) ($decoded['color_palette'] ?? ''),
            'notes' => (string) ($decoded['notes'] ?? ''),
            'updated_prompt' => (string) ($decoded['updated_prompt'] ?? ''),
        ];
    }

    /**
     * @param  mixed  $value
     */
    private function formatJsonBlock(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return 'None';
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'None';
    }

    private function truncate(string $text, int $limit): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'Not provided';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit).'...';
    }

    private function extractJsonObject(string $text): string
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $text, $matches)) {
            return trim($matches[1]);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }
}
