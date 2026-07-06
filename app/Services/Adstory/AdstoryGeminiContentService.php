<?php

namespace App\Services\Adstory;

use Illuminate\Support\Str;
use RuntimeException;

class AdstoryGeminiContentService
{
    public function buildEpisodePlanningPrompt(string $screenplay): string
    {
        return <<<PROMPT
You are a professional production breakdown artist. Analyze the screenplay and estimate how many distinct production scenes it contains.

Rules:
- Count only distinct story moments or location/time changes that would become separate storyboard scenes.
- Do not generate full scene descriptions.
- Do not generate shots.
- Optionally provide a short title and one-line summary per episode chunk (max 5 scenes per episode).
- Return only valid JSON.
- No markdown.
- No explanation.

JSON format must be exactly:

{
  "estimated_scene_count": 30,
  "episodes": [
    {
      "title": "Episode 1 title",
      "summary": "One-line summary of scenes 1-5"
    }
  ]
}

The episodes array is optional but helpful. If provided, each entry corresponds to a chunk of up to 5 consecutive scenes.

Screenplay:
{$screenplay}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseEpisodePlanningJson(string $text): array
    {
        $json = $this->extractJsonObject($text);
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new RuntimeException('Failed to parse episode plan from Gemini response: invalid JSON.');
        }

        $count = (int) ($decoded['estimated_scene_count'] ?? 0);

        if ($count < 1) {
            throw new RuntimeException('Failed to parse episode plan: estimated_scene_count must be at least 1.');
        }

        $episodes = is_array($decoded['episodes'] ?? null) ? $decoded['episodes'] : [];

        return [
            'estimated_scene_count' => $count,
            'episodes' => array_values(array_map(fn (array $item, int $index) => [
                'title' => (string) ($item['title'] ?? 'Episode '.($index + 1)),
                'summary' => (string) ($item['summary'] ?? ''),
            ], $episodes, array_keys($episodes))),
        ];
    }

    public function buildEpisodeScenesPrompt(
        string $screenplay,
        int $startSceneNumber,
        int $endSceneNumber,
        ?string $episodeTitle,
        ?string $episodeSummary,
        ?string $style,
    ): string {
        $styleInstruction = $style
            ? "The video storyboard style is: {$style}."
            : 'Use a cinematic storyboard-friendly style suitable for video production.';

        $context = trim(($episodeTitle ?? '')."\n".($episodeSummary ?? ''));

        return <<<PROMPT
You are a professional production breakdown artist. Generate full production scenes from the screenplay for ONE episode only.

Rules:
- Generate ONLY scenes numbered {$startSceneNumber} through {$endSceneNumber} (inclusive).
- Maximum {$endSceneNumber} scenes total for this batch — at most 5 scenes.
- Do not change story meaning.
- Do not add new plot points.
- Do not generate shots.
- Return only valid JSON.
- No markdown.
- No explanation.

{$styleInstruction}

Episode context:
{$context}

JSON format must be exactly:

[
  {
    "scene_number": {$startSceneNumber},
    "title": "Scene title",
    "location": "Main location",
    "time_of_day": "Day / Night / Sunset / Morning / Unknown",
    "description": "Full scene description",
    "mood": "Scene mood",
    "environment": "Detailed environment description for storyboard",
    "characters": ["Character 1"],
    "visual_style": "Optional style notes"
  }
]

Screenplay:
{$screenplay}
PROMPT;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseEpisodeScenesJson(string $text): array
    {
        $items = $this->parseGeminiJsonArray($text, 'episode scenes');

        return array_values(array_map(function (array $item, int $index) {
            return [
                'scene_number' => (int) ($item['scene_number'] ?? ($index + 1)),
                'title' => (string) ($item['title'] ?? 'Untitled scene'),
                'location' => (string) ($item['location'] ?? ''),
                'time_of_day' => (string) ($item['time_of_day'] ?? 'Unknown'),
                'description' => (string) ($item['description'] ?? ($item['summary'] ?? '')),
                'mood' => (string) ($item['mood'] ?? ''),
                'environment' => (string) ($item['environment'] ?? ''),
                'characters' => is_array($item['characters'] ?? null) ? $item['characters'] : [],
                'visual_style' => $item['visual_style'] ?? null,
            ];
        }, $items, array_keys($items)));
    }

    /**
     * @param  array<string, mixed>  $scene
     * @param  list<array<string, mixed>>  $characters
     * @param  list<array<string, mixed>>  $environments
     */
    public function buildShotsForScenePrompt(
        array $scene,
        ?string $style,
        ?string $screenplayContext = null,
        array $characters = [],
        array $environments = [],
    ): string {
        $styleInstruction = $style
            ? "The video storyboard style is: {$style}. Reflect this style in shot composition, lighting, and mood."
            : 'Use a cinematic storyboard-friendly style suitable for video production.';

        $sceneJson = json_encode($scene, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $screenplayBlock = '';

        if ($screenplayContext !== null && trim($screenplayContext) !== '') {
            $screenplayBlock = "\nScreenplay context (for reference only — generate shots for the single scene below):\n"
                .trim($screenplayContext)."\n";
        }

        $charactersBlock = '';
        if ($characters !== []) {
            $charactersJson = json_encode($characters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $charactersBlock = "\nProject characters (use for reference when naming and describing characters in shots):\n{$charactersJson}\n";
        }

        $environmentsBlock = '';
        if ($environments !== []) {
            $environmentsJson = json_encode($environments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $environmentsBlock = "\nProject environments (use for reference when describing locations and settings in shots):\n{$environmentsJson}\n";
        }

        return <<<PROMPT
You are a professional cinematographer and storyboard artist. Break the single scene below into small production shots for storyboard image generation.

Rules:
- Generate shots for this scene only.
- Do not change story meaning.
- Do not add new plot points.
- Produce 3 to 6 shots for this scene.
- Shots should be practical for storyboard image generation.
- Include cinematography details.
- Include shot size, camera angle, camera movement, composition, lighting, mood, characters, environment, and duration.
- Return only valid JSON.
- No markdown.
- No explanation.
- No text before or after JSON.

{$styleInstruction}
{$screenplayBlock}{$charactersBlock}{$environmentsBlock}
JSON format must be exactly:

[
  {
    "scene_number": 1,
    "shot_number": 1,
    "title": "Shot title",
    "description": "What happens visually in this shot",
    "shot_size": "Wide shot / Medium shot / Close-up",
    "camera_angle": "Eye level / Low angle / High angle",
    "camera_movement": "Static / Push in / Pan / Tracking",
    "composition": "Rule of thirds / Center framed / Symmetrical",
    "lighting": "Lighting direction and mood",
    "mood": "Shot mood",
    "characters": ["Character 1"],
    "environment": "Environment description",
    "duration_seconds": 3
  }
]

Scene:
{$sceneJson}
PROMPT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenes
     */
    public function buildShotsPrompt(array $scenes, ?string $style): string
    {
        $styleInstruction = $style
            ? "The video storyboard style is: {$style}. Reflect this style in shot composition, lighting, and mood."
            : 'Use a cinematic storyboard-friendly style suitable for video production.';

        $scenesJson = json_encode($scenes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
You are a professional cinematographer and storyboard artist. Break each scene below into small production shots for storyboard image generation.

Rules:
- Do not change story meaning.
- Do not add new plot points.
- Preserve scene order.
- Each scene should have 3 to 6 shots.
- Shots should be practical for storyboard image generation.
- Include cinematography details.
- Include shot size, camera angle, camera movement, composition, lighting, mood, characters, environment, and duration.
- Return only valid JSON.
- No markdown.
- No explanation.
- No text before or after JSON.

{$styleInstruction}

JSON format must be exactly:

[
  {
    "scene_number": 1,
    "shot_number": 1,
    "title": "Shot title",
    "description": "What happens visually in this shot",
    "shot_size": "Wide shot / Medium shot / Close-up",
    "camera_angle": "Eye level / Low angle / High angle",
    "camera_movement": "Static / Push in / Pan / Tracking",
    "composition": "Rule of thirds / Center framed / Symmetrical",
    "lighting": "Lighting direction and mood",
    "mood": "Shot mood",
    "characters": ["Character 1"],
    "environment": "Environment description",
    "duration_seconds": 3
  }
]

Scenes:
{$scenesJson}
PROMPT;
    }

    public function buildExtractCharactersPrompt(string $screenplay, ?string $scenesContext = null): string
    {
        $scenesBlock = '';
        if ($scenesContext !== null && trim($scenesContext) !== '') {
            $scenesBlock = "\nCompleted scenes (use as additional context for character names and roles):\n".trim($scenesContext)."\n";
        }

        return <<<PROMPT
You are a professional script breakdown analyst. Analyze the following screenplay and extract every unique human character.

Rules:
- Return only actual people.
- Ignore objects.
- Ignore animals.
- Ignore locations.
- Ignore buildings.
- Ignore vehicles.
- Ignore narrator.
- Ignore camera.
- Ignore props.
- Merge duplicate names referring to the same person (e.g. "Old Farmer", "Farmer", "Mr Juma" should become one character).
- Do not invent extra characters not present in the screenplay.
- Do not generate image prompts.
- Do not generate images.
- Return only valid JSON.
- No markdown.
- No explanation.
- No text before or after JSON.

Each character must include:
- id (lowercase slug from the canonical name, e.g. "john" for "John")
- name
- role
- gender
- age (estimated age as a string)
- description (short appearance description)
- importance (e.g. Primary, Secondary, Supporting)

JSON format must be exactly:

[
  {
    "id": "john",
    "name": "John",
    "role": "Main Character",
    "gender": "Male",
    "age": "45",
    "description": "A hardworking farmer.",
    "importance": "Primary"
  }
]

Screenplay:
{$screenplay}{$scenesBlock}
PROMPT;
    }

    public function buildExtractEnvironmentsPrompt(string $screenplay, ?string $scenesContext = null): string
    {
        $scenesBlock = '';
        if ($scenesContext !== null && trim($scenesContext) !== '') {
            $scenesBlock = "\nCompleted scene locations (use as additional context):\n".trim($scenesContext)."\n";
        }

        return <<<PROMPT
You are a professional script breakdown analyst. Analyze the following screenplay and extract all unique environments and locations.

Rules:
- Return only places/environments.
- Ignore people.
- Ignore props.
- Ignore animals.
- Ignore vehicles unless the vehicle is the main setting of a scene.
- Merge duplicate locations referring to the same place.
- Preserve important visual details.
- Do not generate image prompts.
- Do not generate shots.
- Do not invent locations not present in the screenplay.
- Return only valid JSON.
- No markdown.
- No explanation.
- No text before or after JSON.

Each environment must include:
- id (lowercase slug with underscores from the canonical name, e.g. "village_square" for "Village Square")
- name
- type (Indoor / Outdoor / Vehicle / Fantasy / Other)
- time_of_day (Morning / Day / Sunset / Night / Mixed / Unknown)
- description (short visual description of the environment)
- mood (environment mood)
- importance (Primary / Secondary / Background)

JSON format must be exactly:

[
  {
    "id": "village_square",
    "name": "Village Square",
    "type": "Outdoor",
    "time_of_day": "Morning",
    "description": "Short visual description of the environment.",
    "mood": "Environment mood",
    "importance": "Primary"
  }
]

Screenplay:
{$screenplay}
{$scenesBlock}
PROMPT;
    }

    public const CHARACTER_IMAGE_NEGATIVE_PROMPT = 'multiple people, group, crowd, duplicate person, two people, three people, lineup, collage, split screen, background people, extra bodies, reflections, mannequins, background figures, character sheet, contact sheet';

    /**
     * @param  array<string, mixed>  $character
     * @return array{prompt: string, negative_prompt: string, full_prompt: string}
     */
    public function buildCharacterImagePromptBundle(array $character, ?string $style): array
    {
        $visualStyle = $style ?? 'Cinematic Realistic';
        $name = trim((string) ($character['name'] ?? 'Character'));
        $role = trim((string) ($character['role'] ?? 'Unknown role'));
        $gender = trim((string) ($character['gender'] ?? 'Unknown'));
        $age = trim((string) ($character['age'] ?? 'Unknown'));
        $description = trim((string) ($character['description'] ?? ''));
        $importance = trim((string) ($character['importance'] ?? 'Unknown'));
        $personDescription = $this->buildCharacterPersonDescription($character);

        $prompt = <<<PROMPT
Create a single-character reference image of {$name}.
Only one person should appear in the image.
The person must be {$personDescription}.
Solo portrait, centered, clean background, {$visualStyle} style.
Do not include any other people, duplicates, crowd, extra bodies, reflections, mannequins, or background figures.
Do not show multiple versions of the character.
Do not create a lineup or collage.

Strict rules:
- Generate only one person.
- The image must be a solo character portrait.
- No extra people.
- No background characters.
- No duplicate versions of the same person.
- No group shot.
- No split-screen.
- No multiple poses in one image.
- No before/after comparison.
- No collage.
- No character lineup.
- Full body or portrait of one character only.
- Front-facing pose with clear face and outfit.
- No text.
- No watermark.
- No distorted hands.
- Preserve character age, gender, role, and description.

Character details:
- Name: {$name}
- Role: {$role}
- Gender: {$gender}
- Age: {$age}
- Importance: {$importance}
- Description: {$description}
PROMPT;

        $negativePrompt = self::CHARACTER_IMAGE_NEGATIVE_PROMPT;

        return [
            'prompt' => trim($prompt),
            'negative_prompt' => $negativePrompt,
            'full_prompt' => trim($prompt)."\n\nAvoid generating: {$negativePrompt}.",
        ];
    }

    /**
     * @param  array<string, mixed>  $character
     */
    public function buildCharacterImagePrompt(array $character, ?string $style): string
    {
        return $this->buildCharacterImagePromptBundle($character, $style)['full_prompt'];
    }

    /**
     * @param  array<string, mixed>  $character
     */
    private function buildCharacterPersonDescription(array $character): string
    {
        $description = trim((string) ($character['description'] ?? $character['appearance'] ?? ''));

        if ($description !== '') {
            return $description;
        }

        $parts = array_filter([
            isset($character['gender']) && (string) $character['gender'] !== ''
                ? (string) $character['gender']
                : null,
            isset($character['age']) && (string) $character['age'] !== ''
                ? 'age '.(string) $character['age']
                : null,
            isset($character['role']) && (string) $character['role'] !== ''
                ? (string) $character['role']
                : null,
        ]);

        return $parts !== [] ? implode(', ', $parts) : 'as described in the character details';
    }

    public const ENVIRONMENT_IMAGE_NEGATIVE_PROMPT = 'people, crowd, characters, silhouettes, animals, human figures, pedestrians, background people, text, watermark, logos, ui elements, collage, split screen, character lineup';

    /**
     * @param  array<string, string>  $environment
     * @return array{prompt: string, negative_prompt: string, full_prompt: string}
     */
    public function buildEnvironmentImagePromptBundle(array $environment, ?string $style): array
    {
        $visualStyle = $style ?? 'Cinematic Realistic';
        $name = trim((string) ($environment['name'] ?? 'Environment'));
        $type = trim((string) ($environment['type'] ?? 'Unknown'));
        $timeOfDay = trim((string) ($environment['time_of_day'] ?? 'Unknown'));
        $description = trim((string) ($environment['description'] ?? ''));
        $mood = trim((string) ($environment['mood'] ?? 'Unknown'));
        $lighting = trim((string) ($environment['lighting'] ?? ''));
        $importance = trim((string) ($environment['importance'] ?? 'Unknown'));

        $lightingLine = $lighting !== '' ? "- Lighting: {$lighting}\n" : '';

        $prompt = <<<PROMPT
Create a single-environment production reference image of {$name}.
Only the location/environment should appear in the image.
This must be a photorealistic cinematic production reference with no living subjects.

Environment details:
- Name: {$name}
- Type: {$type}
- Time of day: {$timeOfDay}
- Mood: {$mood}
- Importance: {$importance}
- Description: {$description}
{$lightingLine}
Strict rules:
- Environment only.
- No people.
- No crowd.
- No characters.
- No silhouettes.
- No animals.
- No text.
- No watermark.
- No UI elements.
- No logos.
- No vehicles with visible occupants.
- Show clear location layout, depth, and spatial composition.
- Show lighting and mood clearly.
- Photorealistic cinematic production reference style: {$visualStyle}.
PROMPT;

        $negativePrompt = self::ENVIRONMENT_IMAGE_NEGATIVE_PROMPT;

        return [
            'prompt' => trim($prompt),
            'negative_prompt' => $negativePrompt,
            'full_prompt' => trim($prompt)."\n\nAvoid generating: {$negativePrompt}.",
        ];
    }

    /**
     * @param  array<string, string>  $environment
     */
    public function buildEnvironmentImagePrompt(array $environment, ?string $style): string
    {
        return $this->buildEnvironmentImagePromptBundle($environment, $style)['full_prompt'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseShotsJson(string $text): array
    {
        return $this->parseGeminiJsonArray($text, 'shots');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseCharactersJson(string $text): array
    {
        return $this->parseGeminiJsonArray($text, 'characters');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseEnvironmentsJson(string $text): array
    {
        return $this->parseGeminiJsonArray($text, 'environments');
    }

    /**
     * @param  array<int, array<string, mixed>>  $characters
     * @return array<int, array<string, string>>
     */
    public function normalizeCharacters(array $characters): array
    {
        $usedIds = [];

        return array_values(array_map(function (array $character) use (&$usedIds) {
            $name = trim((string) ($character['name'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Failed to parse characters from Gemini response: character missing name.');
            }

            $id = ! empty($character['id'])
                ? (string) $character['id']
                : Str::slug($name);

            $baseId = $id;
            $suffix = 2;

            while (in_array($id, $usedIds, true)) {
                $id = $baseId.'-'.$suffix;
                $suffix++;
            }

            $usedIds[] = $id;

            return [
                'id' => $id,
                'name' => $name,
                'role' => (string) ($character['role'] ?? ''),
                'gender' => (string) ($character['gender'] ?? 'Unknown'),
                'age' => (string) ($character['age'] ?? 'Unknown'),
                'description' => (string) ($character['description'] ?? ''),
                'importance' => (string) ($character['importance'] ?? 'Secondary'),
            ];
        }, $characters));
    }

    /**
     * @param  array<int, array<string, mixed>>  $environments
     * @return array<int, array<string, string>>
     */
    public function normalizeEnvironments(array $environments): array
    {
        $usedIds = [];

        return array_values(array_map(function (array $environment) use (&$usedIds) {
            $name = trim((string) ($environment['name'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Failed to parse environments from Gemini response: environment missing name.');
            }

            $id = ! empty($environment['id'])
                ? (string) $environment['id']
                : Str::slug($name, '_');

            $baseId = $id;
            $suffix = 2;

            while (in_array($id, $usedIds, true)) {
                $id = $baseId.'_'.$suffix;
                $suffix++;
            }

            $usedIds[] = $id;

            return [
                'id' => $id,
                'name' => $name,
                'type' => (string) ($environment['type'] ?? 'Other'),
                'time_of_day' => (string) ($environment['time_of_day'] ?? 'Unknown'),
                'description' => (string) ($environment['description'] ?? ''),
                'mood' => (string) ($environment['mood'] ?? ''),
                'importance' => (string) ($environment['importance'] ?? 'Secondary'),
            ];
        }, $environments));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseGeminiJsonArray(string $text, string $resource): array
    {
        $json = $this->extractJsonArray($text);
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Failed to parse {$resource} from Gemini response: invalid JSON.");
        }

        if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === []) {
            throw new RuntimeException("Failed to parse {$resource} from Gemini response: expected a non-empty JSON array.");
        }

        return $decoded;
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

    private function extractJsonArray(string $text): string
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $text, $matches)) {
            return trim($matches[1]);
        }

        $start = strpos($text, '[');
        $end = strrpos($text, ']');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }

    public function sanitizeAssetId(string $id): string
    {
        $sanitized = Str::slug((string) $id, '_');

        if ($sanitized === '') {
            throw new RuntimeException('Invalid asset id provided.');
        }

        return $sanitized;
    }

    public function publicStorageUrl(string $storagePath): string
    {
        $normalizedPath = ltrim(str_replace('\\', '/', $storagePath), '/');

        return rtrim((string) config('app.url'), '/').'/storage/'.$normalizedPath;
    }
}
