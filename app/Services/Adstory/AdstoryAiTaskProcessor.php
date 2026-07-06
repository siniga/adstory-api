<?php

namespace App\Services\Adstory;

use App\Models\AdstoryAiTask;
use App\Models\AdstoryCharacter;
use App\Models\AdstoryEnvironment;
use App\Models\AdstoryEpisode;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AdstoryAiTaskProcessor
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly AdstoryGeminiContentService $contentService,
        private readonly AdstorySceneService $sceneService,
        private readonly AdstoryShotService $shotService,
        private readonly AdstoryCharacterService $characterService,
        private readonly AdstoryEnvironmentService $environmentService,
        private readonly AdstoryCharacterAssetService $characterAssetService,
        private readonly AdstoryEnvironmentAssetService $environmentAssetService,
        private readonly AdstoryCharacterGenerationService $characterGenerationService,
        private readonly AdstoryEnvironmentGenerationService $environmentGenerationService,
        private readonly AdstoryShotImageService $shotImageService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function process(AdstoryAiTask $task): array
    {
        return match ($task->type) {
            AdstoryAiTask::TYPE_GENERATE_SCENE => $this->processGenerateSceneTask($task),
            AdstoryAiTask::TYPE_GENERATE_EPISODE_SCENES => $this->processGenerateEpisodeScenesTask($task),
            AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE => $this->processGenerateShotsForSceneTask($task),
            AdstoryAiTask::TYPE_GENERATE_STORYBOARD_IMAGE_FOR_SHOT => $this->processGenerateStoryboardImageForShotTask($task),
            AdstoryAiTask::TYPE_EXTRACT_CHARACTERS => $this->processExtractCharactersTask($task),
            AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE => $this->processGenerateCharacterImageTask($task),
            AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS => $this->processExtractEnvironmentsTask($task),
            AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE => $this->processGenerateEnvironmentImageTask($task),
            default => throw new RuntimeException("Unsupported AI task type: {$task->type}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function processGenerateSceneTask(AdstoryAiTask $task): array
    {
        $project = AdstoryProject::query()->find($task->adstory_project_id);

        if (! $project) {
            throw new RuntimeException('Project no longer exists.');
        }

        $scene = AdstoryScene::query()
            ->where('adstory_project_id', $project->id)
            ->where('id', $task->taskable_id)
            ->first();

        if (! $scene) {
            throw new RuntimeException('Scene no longer exists.');
        }

        $screenplay = trim((string) ($project->screenplay ?? ''));

        if ($screenplay === '') {
            throw new RuntimeException('Project screenplay is missing.');
        }

        $scene->update([
            'status' => AdstorySceneGenerationService::SCENE_STATUS_GENERATING,
            'generation_error' => null,
        ]);

        $payload = is_array($task->payload) ? $task->payload : [];
        $summary = (string) ($payload['summary'] ?? ($scene->meta['summary'] ?? $scene->description ?? ''));

        $prompt = $this->buildSingleScenePrompt(
            screenplay: $screenplay,
            sceneNumber: (int) ($scene->scene_number ?? 0),
            title: (string) ($scene->title ?? $payload['title'] ?? ''),
            location: (string) ($scene->location ?? $payload['location'] ?? ''),
            timeOfDay: (string) ($scene->time_of_day ?? $payload['time_of_day'] ?? ''),
            summary: $summary,
            style: $scene->visual_style ?? $project->visual_style,
        );

        Log::info('Adstory AI task: Gemini request started', [
            'task_id' => $task->id,
            'type' => $task->type,
            'scene_id' => $scene->id,
        ]);

        $responseText = $this->geminiService->generateText($prompt);
        $sceneData = $this->parseSingleSceneJson($responseText);

        $meta = is_array($scene->meta ?? null) ? $scene->meta : [];
        $meta['characters'] = $sceneData['characters'] ?? ($meta['characters'] ?? []);
        $meta['generation_plan'] = $meta['generation_plan'] ?? $payload;

        $title = (string) ($sceneData['title'] ?? $scene->title ?? 'Untitled scene');

        $attributes = $this->sceneService->mapSceneAttributes(
            projectId: $project->id,
            data: array_merge($sceneData, [
                'title' => $title,
                'slug' => \Illuminate\Support\Str::slug($title),
                'order_index' => $scene->order_index,
                'status' => AdstorySceneGenerationService::SCENE_STATUS_COMPLETED,
                'meta' => $meta,
            ]),
            orderIndex: $scene->order_index,
            visualStyle: $sceneData['visual_style'] ?? $scene->visual_style ?? $project->visual_style,
        );

        unset($attributes['adstory_project_id']);

        $scene->fill($attributes);
        $scene->save();

        $this->sceneService->markSceneCompleted($scene->fresh());

        return [
            'scene_id' => $scene->id,
            'scene_number' => $scene->scene_number,
            'title' => $scene->title,
            'status' => AdstorySceneGenerationService::SCENE_STATUS_COMPLETED,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function processGenerateEpisodeScenesTask(AdstoryAiTask $task): array
    {
        $project = AdstoryProject::query()
            ->select(['id', 'screenplay', 'visual_style'])
            ->find($task->adstory_project_id);

        if (! $project) {
            throw new RuntimeException('Project no longer exists.');
        }

        $episode = AdstoryEpisode::query()
            ->where('adstory_project_id', $project->id)
            ->where('id', $task->taskable_id)
            ->first();

        if (! $episode) {
            throw new RuntimeException('Episode no longer exists.');
        }

        $screenplay = trim((string) ($project->screenplay ?? ''));

        if ($screenplay === '') {
            throw new RuntimeException('Project screenplay is missing.');
        }

        $payload = is_array($task->payload) ? $task->payload : [];
        $start = (int) ($episode->start_scene_number ?? ($payload['start_scene_number'] ?? 1));
        $end = (int) ($episode->end_scene_number ?? ($payload['end_scene_number'] ?? $start));
        $style = $payload['style'] ?? $project->visual_style;

        $episode->update([
            'status' => AdstoryEpisode::STATUS_SCENES_GENERATING,
            'scene_generation_status' => 'generating',
            'scene_generation_error' => null,
        ]);

        $prompt = $this->contentService->buildEpisodeScenesPrompt(
            screenplay: $screenplay,
            startSceneNumber: $start,
            endSceneNumber: $end,
            episodeTitle: $episode->title,
            episodeSummary: $episode->summary,
            style: $style,
        );

        Log::info('Adstory AI task: Gemini episode scenes request started', [
            'task_id' => $task->id,
            'episode_id' => $episode->id,
            'start_scene_number' => $start,
            'end_scene_number' => $end,
        ]);

        $responseText = $this->geminiService->generateText($prompt);
        $scenesData = $this->contentService->parseEpisodeScenesJson($responseText);

        $savedSceneIds = [];

        foreach ($scenesData as $index => $sceneData) {
            $sceneNumber = (int) ($sceneData['scene_number'] ?? ($start + $index));

            if ($sceneNumber < $start || $sceneNumber > $end) {
                continue;
            }

            $title = (string) ($sceneData['title'] ?? 'Untitled scene');
            $meta = [
                'characters' => $sceneData['characters'] ?? [],
                'episode_id' => $episode->id,
            ];

            $attributes = $this->sceneService->mapSceneAttributes(
                projectId: $project->id,
                data: array_merge($sceneData, [
                    'adstory_episode_id' => $episode->id,
                    'title' => $title,
                    'slug' => \Illuminate\Support\Str::slug($title),
                    'order_index' => $sceneNumber - 1,
                    'status' => AdstorySceneGenerationService::SCENE_STATUS_COMPLETED,
                    'meta' => $meta,
                ]),
                orderIndex: $sceneNumber - 1,
                visualStyle: $sceneData['visual_style'] ?? $style,
            );

            $scene = AdstoryScene::query()->create($attributes);
            $this->sceneService->markSceneCompleted($scene->fresh());
            $savedSceneIds[] = $scene->id;
        }

        if ($savedSceneIds === []) {
            throw new RuntimeException('No scenes were generated for this episode.');
        }

        $episode->fresh()->markSceneGenerationCompleted();

        Log::info('Adstory AI task: episode scenes saved', [
            'task_id' => $task->id,
            'episode_id' => $episode->id,
            'scenes_count' => count($savedSceneIds),
        ]);

        return [
            'episode_id' => $episode->id,
            'scenes_count' => count($savedSceneIds),
            'scene_ids' => $savedSceneIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function processGenerateShotsForSceneTask(AdstoryAiTask $task): array
    {
        $task->refresh();

        if ($task->status === AdstoryAiTask::STATUS_CANCELLED) {
            return [
                'scene_id' => $task->taskable_id,
                'skipped' => true,
                'cancelled' => true,
                'success' => true,
            ];
        }

        try {
            $project = AdstoryProject::query()
                ->select(['id', 'screenplay', 'visual_style'])
                ->find($task->adstory_project_id);

            if (! $project) {
                throw new RuntimeException('Project no longer exists.');
            }

            $scene = AdstoryScene::query()
                ->select([
                    'id', 'adstory_project_id', 'scene_number', 'title', 'slug', 'location',
                    'environment', 'time_of_day', 'description', 'screenplay_excerpt', 'mood',
                    'visual_style', 'status', 'meta',
                ])
                ->where('adstory_project_id', $project->id)
                ->where('id', $task->taskable_id)
                ->first();

            if (! $scene) {
                throw new RuntimeException('Scene no longer exists.');
            }

            if ($scene->status !== AdstorySceneGenerationService::SCENE_STATUS_COMPLETED) {
                throw new RuntimeException('Scene must be completed before generating shots.');
            }

            if ($scene->shots()->exists()) {
                $scene->markShotGenerationCompleted();

                Log::info("Scene {$scene->scene_number} -> skipped, shots already exist", [
                    'task_id' => $task->id,
                    'scene_id' => $scene->id,
                    'project_id' => $project->id,
                    'shots_count' => $scene->shots()->count(),
                ]);

                return [
                    'scene_id' => $scene->id,
                    'scene_number' => $scene->scene_number,
                    'shots_count' => $scene->shots()->count(),
                    'skipped' => true,
                    'success' => true,
                ];
            }

            $originalSceneStatus = $scene->status;

            Log::info("Starting shot generation for scene {$scene->id}", [
                'task_id' => $task->id,
                'scene_id' => $scene->id,
                'scene_number' => $scene->scene_number,
                'project_id' => $project->id,
            ]);

            $payload = is_array($task->payload) ? $task->payload : [];
            $style = $payload['style'] ?? $scene->visual_style ?? $project->visual_style;

            $sceneMeta = is_array($scene->meta ?? null) ? $scene->meta : [];

            $scenePayload = [
                'scene_number' => $scene->scene_number,
                'title' => $scene->title,
                'location' => $scene->location,
                'time_of_day' => $scene->time_of_day,
                'description' => $scene->description,
                'mood' => $scene->mood,
                'characters' => $sceneMeta['characters'] ?? [],
                'environment' => $scene->environment ?? ($sceneMeta['environment'] ?? null),
                'screenplay_excerpt' => $scene->screenplay_excerpt,
            ];

            $screenplayContext = trim((string) ($scene->screenplay_excerpt ?? ''));
            if ($screenplayContext === '') {
                $screenplay = trim((string) ($project->screenplay ?? ''));
                if ($screenplay !== '') {
                    $screenplayContext = mb_strlen($screenplay) > 6000
                        ? mb_substr($screenplay, 0, 6000).'…'
                        : $screenplay;
                }
            }

            $scene->markShotGenerationGenerating();

            $characterContext = $this->buildCharacterContextForShotGeneration($project->id);
            $environmentContext = $this->buildEnvironmentContextForShotGeneration($project->id);

            $prompt = $this->contentService->buildShotsForScenePrompt(
                scene: $scenePayload,
                style: $style,
                screenplayContext: $screenplayContext !== '' ? $screenplayContext : null,
                characters: $characterContext,
                environments: $environmentContext,
            );

            Log::info('Adstory AI task: Gemini request started', [
                'task_id' => $task->id,
                'type' => $task->type,
                'scene_id' => $scene->id,
                'scene_number' => $scene->scene_number,
            ]);

            $responseText = $this->geminiService->generateText($prompt);
            $allShots = $this->contentService->parseShotsJson($responseText);

            $sceneNumber = (int) $scene->scene_number;
            $sceneShots = array_values(array_filter(
                $allShots,
                fn (array $shot) => (int) ($shot['scene_number'] ?? 0) === $sceneNumber
            ));

            if ($sceneShots === []) {
                $sceneShots = $allShots;
            }

            $savedShots = $this->shotService->replaceSceneShots(
                project: $project,
                scene: $scene,
                shotsData: $sceneShots,
            );

            $scene->markShotGenerationCompleted();

            $scene->refresh();
            if ($scene->status !== $originalSceneStatus) {
                AdstoryScene::query()
                    ->where('id', $scene->id)
                    ->update(['status' => $originalSceneStatus]);
            }

            $shotsCount = count($savedShots);

            Log::info("Scene {$scene->scene_number} -> Generated {$shotsCount} shots", [
                'task_id' => $task->id,
                'type' => $task->type,
                'scene_id' => $scene->id,
                'project_id' => $project->id,
                'shots_count' => $shotsCount,
                'scene_status' => $originalSceneStatus,
            ]);

            return [
                'scene_id' => $scene->id,
                'scene_number' => $scene->scene_number,
                'shots_count' => $shotsCount,
                'shot_ids' => array_column($savedShots, 'id'),
                'success' => true,
            ];
        } catch (Throwable $e) {
            if (isset($scene) && $scene instanceof AdstoryScene) {
                $scene->markShotGenerationFailed($e->getMessage());
            }

            Log::error("Scene shot generation failed for scene {$task->taskable_id}", [
                'task_id' => $task->id,
                'scene_id' => $task->taskable_id,
                'project_id' => $task->adstory_project_id,
                'message' => $e->getMessage(),
            ]);

            return [
                'scene_id' => $task->taskable_id,
                'success' => false,
                'failed' => true,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function processGenerateStoryboardImageForShotTask(AdstoryAiTask $task): array
    {
        Log::info('Adstory storyboard shot image task: delegating to queue job', [
            'task_id' => $task->id,
            'shot_id' => $task->taskable_id,
            'project_id' => $task->adstory_project_id,
        ]);

        $task->refresh();

        if ($task->status === AdstoryAiTask::STATUS_CANCELLED) {
            if ($task->taskable_id) {
                $shot = AdstoryShot::query()->find($task->taskable_id);

                if ($shot) {
                    $shot->markStoryboardImageCancelled();
                }
            }

            return [
                'shot_id' => $task->taskable_id,
                'skipped' => true,
                'cancelled' => true,
                'success' => true,
            ];
        }

        $shot = AdstoryShot::query()
            ->where('adstory_project_id', $task->adstory_project_id)
            ->where('id', $task->taskable_id)
            ->first();

        if (! $shot) {
            return [
                'shot_id' => $task->taskable_id,
                'skipped' => true,
                'success' => true,
                'reason' => 'shot_missing',
            ];
        }

        $payload = is_array($task->payload) ? $task->payload : [];
        $force = (bool) ($payload['force'] ?? false);

        if (
            ! $force
            && $shot->image_status === AdstoryStoryboardService::IMAGE_STATUS_COMPLETED
            && ! empty($shot->image_url)
        ) {
            return [
                'shot_id' => $shot->id,
                'skipped' => true,
                'image_url' => $shot->image_url,
                'success' => true,
            ];
        }

        $jobService = app(AdstoryShotImageJobService::class);

        if (! $jobService->shotIsInFlight($shot)) {
            $jobService->queueShotImageJob($shot, force: $force);
        }

        return [
            'shot_id' => $shot->id,
            'delegated_to_job' => true,
            'success' => true,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildCharacterContextForShotGeneration(int $projectId): array
    {
        return AdstoryCharacter::query()
            ->where('adstory_project_id', $projectId)
            ->select(['name', 'role', 'description', 'appearance', 'gender', 'age'])
            ->orderBy('order_index')
            ->orderBy('id')
            ->get()
            ->map(fn (AdstoryCharacter $character) => [
                'name' => $character->name,
                'role' => $character->role,
                'description' => $character->description ?? $character->appearance,
                'gender' => $character->gender,
                'age' => $character->age,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildEnvironmentContextForShotGeneration(int $projectId): array
    {
        return AdstoryEnvironment::query()
            ->where('adstory_project_id', $projectId)
            ->select(['name', 'location_type', 'type', 'description', 'mood', 'lighting', 'time_of_day'])
            ->orderBy('order_index')
            ->orderBy('id')
            ->get()
            ->map(fn (AdstoryEnvironment $environment) => [
                'name' => $environment->name,
                'type' => $environment->location_type ?? $environment->type,
                'description' => $environment->description,
                'mood' => $environment->mood,
                'lighting' => $environment->lighting,
                'time_of_day' => $environment->time_of_day,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function processExtractCharactersTask(AdstoryAiTask $task): array
    {
        $project = AdstoryProject::query()
            ->select(['id', 'screenplay', 'visual_style'])
            ->find($task->adstory_project_id);

        if (! $project) {
            throw new RuntimeException('Project no longer exists.');
        }

        $screenplay = trim((string) ($project->screenplay ?? ''));
        $scenesContext = $this->buildScenesContextForCharacterExtraction($project->id);

        if ($screenplay === '' && $scenesContext === null) {
            throw new RuntimeException('Project screenplay or completed scenes are required for character extraction.');
        }

        $prompt = $this->contentService->buildExtractCharactersPrompt(
            screenplay: $screenplay !== '' ? $screenplay : 'See completed scenes below.',
            scenesContext: $scenesContext,
        );

        Log::info('Adstory AI task: Gemini request started', [
            'task_id' => $task->id,
            'type' => $task->type,
            'project_id' => $project->id,
        ]);

        $responseText = $this->geminiService->generateText($prompt);
        $characters = $this->contentService->normalizeCharacters(
            $this->contentService->parseCharactersJson($responseText)
        );

        $savedCharacters = $this->characterService->upsertProjectCharacters($project, $characters);

        $payload = is_array($task->payload) ? $task->payload : [];
        $style = $payload['style'] ?? $project->visual_style;

        $this->characterGenerationService->queueImageGenerationTasks($project, $style);

        Log::info('Adstory AI task: related model updated', [
            'task_id' => $task->id,
            'type' => $task->type,
            'characters_count' => count($savedCharacters),
        ]);

        return [
            'characters_count' => count($savedCharacters),
            'characters' => $savedCharacters,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function processGenerateCharacterImageTask(AdstoryAiTask $task): array
    {
        Log::info('Adstory character task: started', [
            'task_id' => $task->id,
            'character_id' => $task->taskable_id,
            'project_id' => $task->adstory_project_id,
        ]);

        try {
            $project = AdstoryProject::query()
                ->select(['id', 'visual_style'])
                ->find($task->adstory_project_id);

            if (! $project) {
                throw new RuntimeException('Project no longer exists.');
            }

            $character = AdstoryCharacter::query()
                ->where('adstory_project_id', $project->id)
                ->where('id', $task->taskable_id)
                ->first();

            if (! $character) {
                throw new RuntimeException('Character no longer exists.');
            }

            if ($character->image_status === AdstoryCharacterGenerationService::IMAGE_STATUS_COMPLETED
                && ! empty($character->image_url)) {
                Log::info('Adstory character task: image already completed, skipping', [
                    'task_id' => $task->id,
                    'character_id' => $character->id,
                ]);

                return [
                    'character_id' => $character->id,
                    'skipped' => true,
                    'image_url' => $character->image_url,
                ];
            }

            $payload = is_array($task->payload) ? $task->payload : [];
            $style = $payload['style'] ?? $project->visual_style;

            $characterInput = [
                'id' => (string) (($character->meta['legacy_id'] ?? $character->meta['id'] ?? $character->id)),
                'name' => (string) $character->name,
                'role' => (string) ($character->role ?? ''),
                'gender' => (string) ($character->gender ?? ''),
                'age' => (string) ($character->age ?? ''),
                'description' => (string) ($character->description ?? $character->appearance ?? ''),
                'importance' => (string) ($character->meta['importance'] ?? ''),
            ];

            $characterId = $this->contentService->sanitizeAssetId($characterInput['id']);
            $promptBundle = $this->contentService->buildCharacterImagePromptBundle($characterInput, $style);

            $meta = is_array($character->meta ?? null) ? $character->meta : [];
            $meta['last_image_prompt'] = $promptBundle['prompt'];
            $meta['last_image_negative_prompt'] = $promptBundle['negative_prompt'];
            if ($style) {
                $meta['last_image_style'] = $style;
            }

            $character->update([
                'prompt' => $promptBundle['full_prompt'],
                'meta' => $meta,
            ]);

            $character->markImageGenerationGenerating();

            Log::info('Adstory character task: Gemini image request started', [
                'task_id' => $task->id,
                'character_id' => $character->id,
            ]);

            $imageBase64 = $this->geminiService->generateImage(
                $promptBundle['prompt'],
                $promptBundle['negative_prompt'],
            );
            $imageData = base64_decode($imageBase64, true);

            if ($imageData === false) {
                throw new RuntimeException('Gemini image generation failed: invalid base64 image data.');
            }

            $this->assertUsableCharacterImageData($imageData);

            $storagePath = "adstory/projects/{$project->id}/characters/{$characterId}/hero/{$this->characterAssetService->uniqueStorageSuffix()}.png";
            Storage::disk('public')->put($storagePath, $imageData);
            $imageUrl = $this->contentService->publicStorageUrl($storagePath);

            $meta['last_image_generated_at'] = now()->toIso8601String();
            $meta['image_storage_path'] = $storagePath;

            $character->update([
                'image_url' => $imageUrl,
                'prompt' => $promptBundle['full_prompt'],
                'meta' => $meta,
            ]);

            $character->markImageGenerationCompleted();

            $asset = $this->characterAssetService->ensureHeroAsset(
                character: $character->fresh(),
                imageUrl: $imageUrl,
                storagePath: $storagePath,
                prompt: $promptBundle['full_prompt'],
            );

            Log::info('Adstory character task: image completed', [
                'task_id' => $task->id,
                'character_id' => $character->id,
                'asset_id' => $asset?->id,
            ]);

            return [
                'character_id' => $character->id,
                'image_url' => $imageUrl,
                'asset_id' => $asset?->id,
                'prompt' => $promptBundle['prompt'],
                'negative_prompt' => $promptBundle['negative_prompt'],
                'success' => true,
            ];
        } catch (Throwable $e) {
            if (isset($character) && $character instanceof AdstoryCharacter) {
                $character->markImageGenerationFailed($e->getMessage());
            }

            Log::error('Adstory character task: image failed', [
                'task_id' => $task->id,
                'character_id' => $task->taskable_id,
                'message' => $e->getMessage(),
            ]);

            return [
                'character_id' => $task->taskable_id,
                'success' => false,
                'failed' => true,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function processExtractEnvironmentsTask(AdstoryAiTask $task): array
    {
        $project = AdstoryProject::query()
            ->select(['id', 'screenplay', 'visual_style'])
            ->find($task->adstory_project_id);

        if (! $project) {
            throw new RuntimeException('Project no longer exists.');
        }

        $screenplay = trim((string) ($project->screenplay ?? ''));
        $scenesContext = $this->buildScenesContextForEnvironmentExtraction($project->id);

        if ($screenplay === '' && $scenesContext === null) {
            throw new RuntimeException('Project screenplay or completed scenes are required for environment extraction.');
        }

        $payload = is_array($task->payload) ? $task->payload : [];
        $style = $payload['style'] ?? $project->visual_style;

        $prompt = $this->contentService->buildExtractEnvironmentsPrompt(
            screenplay: $screenplay !== '' ? $screenplay : 'See completed scene locations below.',
            scenesContext: $scenesContext,
        );

        Log::info('Adstory AI task: Gemini request started', [
            'task_id' => $task->id,
            'type' => $task->type,
            'project_id' => $project->id,
        ]);

        $responseText = $this->geminiService->generateText($prompt);
        $environments = $this->contentService->normalizeEnvironments(
            $this->contentService->parseEnvironmentsJson($responseText)
        );

        $projectForUpsert = AdstoryProject::query()->find($project->id);
        $savedEnvironments = $this->environmentService->upsertProjectEnvironments($projectForUpsert, $environments);

        $this->environmentGenerationService->markExtractionCompleted(
            $projectForUpsert->fresh(),
            count($savedEnvironments),
        );

        Log::info('Adstory AI task: related model updated', [
            'task_id' => $task->id,
            'type' => $task->type,
            'environments_count' => count($savedEnvironments),
        ]);

        return [
            'environments_count' => count($savedEnvironments),
            'environments' => $savedEnvironments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function processGenerateEnvironmentImageTask(AdstoryAiTask $task): array
    {
        Log::info('Adstory environment task: started', [
            'task_id' => $task->id,
            'environment_id' => $task->taskable_id,
            'project_id' => $task->adstory_project_id,
        ]);

        Log::info('Adstory environment task: image generation started', [
            'task_id' => $task->id,
            'environment_id' => $task->taskable_id,
            'project_id' => $task->adstory_project_id,
        ]);

        try {
            $project = AdstoryProject::query()
                ->select(['id', 'visual_style'])
                ->find($task->adstory_project_id);

            if (! $project) {
                throw new RuntimeException('Project no longer exists.');
            }

            $environment = AdstoryEnvironment::query()
                ->where('adstory_project_id', $project->id)
                ->where('id', $task->taskable_id)
                ->first();

            if (! $environment) {
                throw new RuntimeException('Environment no longer exists.');
            }

            if ($environment->image_status === AdstoryEnvironmentGenerationService::IMAGE_STATUS_COMPLETED
                && ! empty($environment->image_url)) {
                Log::info('Adstory environment task: image already completed, skipping', [
                    'task_id' => $task->id,
                    'environment_id' => $environment->id,
                ]);

                return [
                    'environment_id' => $environment->id,
                    'skipped' => true,
                    'image_url' => $environment->image_url,
                ];
            }

            $payload = is_array($task->payload) ? $task->payload : [];
            $style = $payload['style'] ?? $environment->visual_style ?? $project->visual_style;

            $environmentInput = [
                'id' => (string) (($environment->meta['legacy_id'] ?? $environment->meta['id'] ?? $environment->id)),
                'name' => (string) $environment->name,
                'type' => (string) ($environment->location_type ?? $environment->type ?? ''),
                'time_of_day' => (string) ($environment->time_of_day ?? ''),
                'description' => (string) ($environment->description ?? $environment->appearance ?? ''),
                'mood' => (string) ($environment->mood ?? ''),
                'lighting' => (string) ($environment->lighting ?? ''),
                'importance' => (string) ($environment->meta['importance'] ?? ''),
            ];

            $environmentId = $this->contentService->sanitizeAssetId($environmentInput['id']);
            $promptBundle = $this->contentService->buildEnvironmentImagePromptBundle($environmentInput, $style);

            $meta = is_array($environment->meta ?? null) ? $environment->meta : [];
            $meta['last_image_prompt'] = $promptBundle['prompt'];
            $meta['last_image_negative_prompt'] = $promptBundle['negative_prompt'];
            $meta['prompt_used'] = $promptBundle['full_prompt'];
            if ($style) {
                $meta['last_image_style'] = $style;
            }

            $environment->update([
                'prompt' => $promptBundle['full_prompt'],
                'meta' => $meta,
            ]);

            $environment->markImageGenerationGenerating();

            Log::info('Adstory environment task: Gemini image request started', [
                'task_id' => $task->id,
                'environment_id' => $environment->id,
            ]);

            $imageBase64 = $this->geminiService->generateImage(
                $promptBundle['prompt'],
                $promptBundle['negative_prompt'],
            );
            $imageData = base64_decode($imageBase64, true);

            if ($imageData === false) {
                throw new RuntimeException('Gemini image generation failed: invalid base64 image data.');
            }

            $this->assertUsableEnvironmentImageData($imageData);

            $storageSuffix = $this->environmentAssetService->uniqueStorageSuffix();
            $storagePath = "adstory/projects/{$project->id}/environments/{$environmentId}/hero/{$storageSuffix}.png";
            Storage::disk('public')->put($storagePath, $imageData);
            $imageUrl = $this->contentService->publicStorageUrl($storagePath);

            $meta['last_image_generated_at'] = now()->toIso8601String();
            $meta['image_storage_path'] = $storagePath;
            $meta['prompt_used'] = $promptBundle['full_prompt'];

            $environment->update([
                'image_url' => $imageUrl,
                'prompt' => $promptBundle['full_prompt'],
                'meta' => $meta,
            ]);

            $environment->markImageGenerationCompleted();

            $asset = $this->environmentAssetService->createHeroAsset(
                environment: $environment->fresh(),
                imageUrl: $imageUrl,
                storagePath: $storagePath,
                prompt: $promptBundle['full_prompt'],
                meta: $meta,
            );

            Log::info('Adstory environment task: image completed', [
                'task_id' => $task->id,
                'environment_id' => $environment->id,
                'asset_id' => $asset->id,
            ]);

            return [
                'environment_id' => $environment->id,
                'image_url' => $imageUrl,
                'asset_id' => $asset->id,
                'prompt' => $promptBundle['prompt'],
                'negative_prompt' => $promptBundle['negative_prompt'],
                'prompt_used' => $promptBundle['full_prompt'],
                'success' => true,
            ];
        } catch (Throwable $e) {
            if (isset($environment) && $environment instanceof AdstoryEnvironment) {
                $environment->markImageGenerationFailed($e->getMessage());
            }

            Log::error('Adstory environment task: image failed', [
                'task_id' => $task->id,
                'environment_id' => $task->taskable_id,
                'message' => $e->getMessage(),
            ]);

            return [
                'environment_id' => $task->taskable_id,
                'success' => false,
                'failed' => true,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function assertUsableCharacterImageData(string $imageData): void
    {
        if (strlen($imageData) < 1024) {
            throw new RuntimeException('Gemini returned an unusable image: file too small or empty.');
        }

        $isPng = str_starts_with($imageData, "\x89PNG\r\n\x1a\n");
        $isJpeg = str_starts_with($imageData, "\xff\xd8\xff");

        if (! $isPng && ! $isJpeg) {
            throw new RuntimeException('Gemini returned an unusable image: invalid image format.');
        }
    }

    private function assertUsableEnvironmentImageData(string $imageData): void
    {
        $this->assertUsableCharacterImageData($imageData);
    }

    private function buildScenesContextForCharacterExtraction(int $projectId): ?string
    {
        $scenes = AdstoryScene::query()
            ->where('adstory_project_id', $projectId)
            ->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
            ->select(['scene_number', 'title', 'description', 'meta'])
            ->orderBy('order_index')
            ->orderBy('id')
            ->limit(30)
            ->get();

        if ($scenes->isEmpty()) {
            return null;
        }

        $lines = $scenes->map(function (AdstoryScene $scene) {
            $meta = is_array($scene->meta ?? null) ? $scene->meta : [];
            $characters = collect($meta['characters'] ?? [])
                ->filter(fn ($name) => is_string($name) && trim($name) !== '')
                ->implode(', ');

            $parts = array_filter([
                'Scene '.$scene->scene_number.': '.$scene->title,
                $scene->description ? 'Summary: '.$scene->description : null,
                $characters !== '' ? 'Characters: '.$characters : null,
            ]);

            return implode(' | ', $parts);
        });

        return $lines->implode("\n");
    }

    private function buildScenesContextForEnvironmentExtraction(int $projectId): ?string
    {
        $scenes = AdstoryScene::query()
            ->where('adstory_project_id', $projectId)
            ->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
            ->select(['scene_number', 'title', 'location', 'environment', 'time_of_day', 'description'])
            ->orderBy('order_index')
            ->orderBy('id')
            ->limit(30)
            ->get();

        if ($scenes->isEmpty()) {
            return null;
        }

        $lines = $scenes->map(function (AdstoryScene $scene) {
            $parts = array_filter([
                'Scene '.$scene->scene_number.': '.$scene->title,
                $scene->location ? 'Location: '.$scene->location : null,
                $scene->environment ? 'Environment: '.$scene->environment : null,
                $scene->time_of_day ? 'Time: '.$scene->time_of_day : null,
            ]);

            return implode(' | ', $parts);
        });

        return $lines->implode("\n");
    }

    public function handleTaskFailure(AdstoryAiTask $task, string $message): void
    {
        if ($task->type === AdstoryAiTask::TYPE_GENERATE_SCENE && $task->taskable_id) {
            $scene = AdstoryScene::query()->find($task->taskable_id);

            if ($scene) {
                $this->sceneService->markSceneFailed($scene, $message);

                return;
            }
        }

        if ($task->type === AdstoryAiTask::TYPE_GENERATE_EPISODE_SCENES && $task->taskable_id) {
            $episode = AdstoryEpisode::query()->find($task->taskable_id);

            if ($episode) {
                $episode->markSceneGenerationFailed($message);
            }
        }

        if ($task->type === AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE && $task->taskable_id) {
            $scene = AdstoryScene::query()->find($task->taskable_id);

            if ($scene) {
                $scene->markShotGenerationFailed($message);
            }
        }

        if ($task->type === AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE && $task->taskable_id) {
            $character = AdstoryCharacter::query()->find($task->taskable_id);

            if ($character) {
                $character->markImageGenerationFailed($message);
            }
        }

        if ($task->type === AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE && $task->taskable_id) {
            $environment = AdstoryEnvironment::query()->find($task->taskable_id);

            if ($environment) {
                $environment->markImageGenerationFailed($message);
            }
        }

        if ($task->type === AdstoryAiTask::TYPE_GENERATE_STORYBOARD_IMAGE_FOR_SHOT && $task->taskable_id) {
            $shot = AdstoryShot::query()->find($task->taskable_id);

            if ($shot) {
                $shot->markStoryboardImageFailed($message);
            }
        }
    }

    private function buildSingleScenePrompt(
        string $screenplay,
        int $sceneNumber,
        string $title,
        string $location,
        string $timeOfDay,
        string $summary,
        ?string $style,
    ): string {
        $styleInstruction = $style
            ? "The video storyboard style is: {$style}. Reflect this style in mood and environment descriptions."
            : 'Use a cinematic storyboard-friendly style suitable for video production.';

        return <<<PROMPT
You are a professional production breakdown artist. Generate exactly one full production scene from the screenplay.

Rules:
- Generate only scene number {$sceneNumber}.
- Do not change the story meaning.
- Do not add new plot points.
- Use the scene plan context provided.
- Extract a relevant screenplay excerpt for this scene from the screenplay text.
- Return only valid JSON.
- No markdown.
- No explanation.
- No text before or after JSON.

{$styleInstruction}

Scene plan context:
- Scene number: {$sceneNumber}
- Title: {$title}
- Location: {$location}
- Time of day: {$timeOfDay}
- Purpose/summary: {$summary}

JSON format must be exactly:

{
  "scene_number": {$sceneNumber},
  "title": "Scene title",
  "location": "Main location",
  "time_of_day": "Day / Night / Sunset / Morning / Unknown",
  "description": "Full scene description",
  "screenplay_excerpt": "Relevant excerpt from the screenplay for this scene",
  "mood": "Scene mood",
  "visual_style": "Visual style for this scene",
  "environment": "Detailed environment description",
  "characters": ["Character 1", "Character 2"],
  "meta": {}
}

Screenplay:
{$screenplay}
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSingleSceneJson(string $text): array
    {
        $json = $this->extractJsonObject($text);
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new RuntimeException('Gemini returned invalid scene JSON. Please retry this scene.');
        }

        $meta = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];

        if (isset($decoded['characters']) && is_array($decoded['characters'])) {
            $meta['characters'] = $decoded['characters'];
        }

        return [
            'scene_number' => $decoded['scene_number'] ?? null,
            'title' => $decoded['title'] ?? null,
            'location' => $decoded['location'] ?? null,
            'time_of_day' => $decoded['time_of_day'] ?? null,
            'description' => $decoded['description'] ?? null,
            'screenplay_excerpt' => $decoded['screenplay_excerpt'] ?? null,
            'mood' => $decoded['mood'] ?? null,
            'visual_style' => $decoded['visual_style'] ?? null,
            'environment' => $decoded['environment'] ?? null,
            'characters' => $decoded['characters'] ?? [],
            'meta' => $meta === [] ? null : $meta,
        ];
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
