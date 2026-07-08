<?php

namespace App\Http\Controllers\Api\Adstory;

use App\Http\Controllers\Controller;
use App\Models\AdstoryProject;
use App\Services\Adstory\AdstoryCharacterAssetService;
use App\Services\Adstory\AdstoryCharacterService;
use App\Services\Adstory\AdstoryEnvironmentAssetService;
use App\Services\Adstory\AdstoryEnvironmentService;
use App\Services\Adstory\AdstoryAiTaskOrchestratorService;
use App\Services\Adstory\AdstorySceneGenerationService;
use App\Services\Adstory\AdstorySceneService;
use App\Services\Adstory\AdstoryShotService;
use App\Services\Adstory\AdstoryGeminiContentService;
use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use App\Support\ApiErrorResponder;

class StoryGenerationController extends Controller
{
    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly AdstorySceneService $sceneService,
        private readonly AdstorySceneGenerationService $sceneGenerationService,
        private readonly AdstoryShotService $shotService,
        private readonly AdstoryCharacterService $characterService,
        private readonly AdstoryEnvironmentService $environmentService,
        private readonly AdstoryCharacterAssetService $characterAssetService,
        private readonly AdstoryEnvironmentAssetService $environmentAssetService,
        private readonly AdstoryAiTaskOrchestratorService $taskOrchestrator,
        private readonly AdstoryGeminiContentService $contentService,
    ) {}

    public function generateScript(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'story' => 'required|string|min:20',
                'style' => 'nullable|string|max:255',
                'project_id' => 'nullable|integer|exists:adstory_projects,id',
            ]);

            $prompt = $this->buildPrompt(
                story: $validated['story'],
                style: $validated['style'] ?? null,
            );

            $script = $this->geminiService->generateText($prompt);

            $response = [
                'success' => true,
                'script' => $script,
            ];

            if (isset($validated['project_id'])) {
                $project = AdstoryProject::query()->findOrFail($validated['project_id']);
                $project->story = $validated['story'];
                $project->script = $script;

                if (! empty($validated['style'])) {
                    $project->visual_style = $validated['style'];
                }

                $project->current_step = 'script';
                $project->save();

                $response['project'] = $project->fresh()->toApiArray();
            }

            return response()->json($response);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'API key is not configured') ? 500 : 502;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            return ApiErrorResponder::fromThrowable(
                $e,
                'We could not generate the script right now. Please try again in a moment.'
            );
        }
    }

    public function generateScreenplay(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'script' => 'required|string|min:20',
                'style' => 'nullable|string|max:255',
                'project_id' => 'nullable|integer|exists:adstory_projects,id',
            ]);

            $prompt = $this->buildScreenplayPrompt(
                script: $validated['script'],
                style: $validated['style'] ?? null,
            );

            $screenplay = $this->geminiService->generateText($prompt);

            $response = [
                'success' => true,
                'screenplay' => $screenplay,
            ];

            if (isset($validated['project_id'])) {
                $project = AdstoryProject::query()->findOrFail($validated['project_id']);
                $project->script = $validated['script'];
                $project->screenplay = $screenplay;

                if (! empty($validated['style'])) {
                    $project->visual_style = $validated['style'];
                }

                $project->current_step = 'screenplay';
                $project->save();

                $response['project'] = $project->fresh()->toApiArray();
            }

            return response()->json($response);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'API key is not configured') ? 500 : 502;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            return ApiErrorResponder::fromThrowable(
                $e,
                'We could not generate the screenplay right now. Please try again in a moment.'
            );
        }
    }

    public function generateScenes(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'screenplay' => 'required_without:project_id|string|min:20',
                'style' => 'nullable|string|max:255',
                'project_id' => 'nullable|integer|exists:adstory_projects,id',
            ]);

            if ($projectId = $this->resolveProjectId($validated)) {
                Log::info('Adstory generate-scenes: using background generation', ['project_id' => $projectId]);

                $project = AdstoryProject::query()->findOrFail($projectId);

                if (! empty($validated['screenplay'])) {
                    $project->screenplay = $validated['screenplay'];

                    if (! empty($validated['style'])) {
                        $project->visual_style = $validated['style'];
                    }

                    $project->save();
                }

                $result = $this->sceneGenerationService->startGeneration(
                    project: $project->fresh(),
                    visualStyle: $validated['style'] ?? null,
                );

                return response()->json([
                    'success' => true,
                    'scenes' => $result['scenes'],
                    'project' => $result['project'],
                    'progress' => $result['progress'],
                ], 202);
            }

            $prompt = $this->buildScenesPrompt(
                screenplay: $validated['screenplay'],
                style: $validated['style'] ?? null,
            );

            $responseText = $this->geminiService->generateText($prompt);
            $scenes = $this->parseScenesJson($responseText);

            return response()->json([
                'success' => true,
                'scenes' => $scenes,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (RuntimeException $e) {
            $status = match (true) {
                str_contains($e->getMessage(), 'API key is not configured') => 500,
                str_contains($e->getMessage(), 'screenplay') => 422,
                str_contains($e->getMessage(), 'already running') => 409,
                default => 502,
            };

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            return ApiErrorResponder::fromThrowable(
                $e,
                'We could not generate scenes right now. Please try again in a moment.'
            );
        }
    }

    public function generateShots(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'scenes' => 'required_without:project_id|array|min:1',
                'scenes.*.scene_number' => 'required|integer',
                'scenes.*.title' => 'nullable|string',
                'scenes.*.location' => 'nullable|string',
                'scenes.*.time_of_day' => 'nullable|string',
                'scenes.*.description' => 'required|string',
                'scenes.*.mood' => 'nullable|string',
                'scenes.*.characters' => 'nullable|array',
                'scenes.*.environment' => 'nullable|string',
                'style' => 'nullable|string|max:100',
                'project_id' => 'nullable|integer|exists:adstory_projects,id',
            ]);

            if ($projectId = $this->resolveProjectId($validated)) {
                Log::info('Adstory generate-shots: using background generation', ['project_id' => $projectId]);

                $project = AdstoryProject::query()->findOrFail($projectId);
                $result = $this->taskOrchestrator->startShotGeneration(
                    project: $project,
                    style: $validated['style'] ?? null,
                );

                return response()->json($result, 202);
            }

            $prompt = $this->buildShotsPrompt(
                scenes: $validated['scenes'],
                style: $validated['style'] ?? null,
            );

            $responseText = $this->geminiService->generateText($prompt);
            $shots = $this->parseShotsJson($responseText);

            return response()->json([
                'success' => true,
                'shots' => $shots,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (RuntimeException $e) {
            $status = match (true) {
                str_contains($e->getMessage(), 'API key is not configured') => 500,
                str_contains($e->getMessage(), 'already running') => 409,
                default => 502,
            };

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            return ApiErrorResponder::fromThrowable(
                $e,
                'We could not generate shots right now. Please try again in a moment.'
            );
        }
    }

    public function extractCharacters(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'screenplay' => 'required_without:project_id|string|min:20',
                'project_id' => 'nullable|integer|exists:adstory_projects,id',
            ]);

            if ($projectId = $this->resolveProjectId($validated)) {
                Log::info('Adstory extract-characters: using background extraction', ['project_id' => $projectId]);

                $project = AdstoryProject::query()->findOrFail($projectId);

                if (! empty($validated['screenplay'])) {
                    $project->screenplay = $validated['screenplay'];
                    $project->save();
                }

                $result = $this->taskOrchestrator->startCharacterExtraction($project);

                return response()->json($result, 202);
            }

            $prompt = $this->buildExtractCharactersPrompt($validated['screenplay']);

            $responseText = $this->geminiService->generateText($prompt);
            $characters = $this->normalizeCharacters(
                $this->parseCharactersJson($responseText)
            );

            return response()->json([
                'success' => true,
                'characters' => $characters,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (RuntimeException $e) {
            $status = match (true) {
                str_contains($e->getMessage(), 'API key is not configured') => 500,
                str_contains($e->getMessage(), 'already running') => 409,
                default => 502,
            };

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            return ApiErrorResponder::fromThrowable(
                $e,
                'We could not extract characters right now. Please try again in a moment.'
            );
        }
    }

    public function extractEnvironments(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'screenplay' => 'required_without:project_id|string|min:20',
                'project_id' => 'nullable|integer|exists:adstory_projects,id',
            ]);

            if ($projectId = $this->resolveProjectId($validated)) {
                Log::info('Adstory extract-environments: using background extraction', ['project_id' => $projectId]);

                $project = AdstoryProject::query()->findOrFail($projectId);

                if (! empty($validated['screenplay'])) {
                    $project->screenplay = $validated['screenplay'];
                    $project->save();
                }

                $result = $this->taskOrchestrator->startEnvironmentExtraction($project);

                return response()->json($result, 202);
            }

            $prompt = $this->buildExtractEnvironmentsPrompt($validated['screenplay']);

            $responseText = $this->geminiService->generateText($prompt);
            $environments = $this->normalizeEnvironments(
                $this->parseEnvironmentsJson($responseText)
            );

            return response()->json([
                'success' => true,
                'environments' => $environments,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (RuntimeException $e) {
            $status = match (true) {
                str_contains($e->getMessage(), 'API key is not configured') => 500,
                str_contains($e->getMessage(), 'already running') => 409,
                default => 502,
            };

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            return ApiErrorResponder::fromThrowable(
                $e,
                'We could not extract environments right now. Please try again in a moment.'
            );
        }
    }

    public function generateCharacterImage(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'character' => 'required|array',
                'character.id' => 'required|max:100',
                'character.name' => 'required|string|max:150',
                'character.role' => 'nullable|string|max:150',
                'character.gender' => 'nullable|string|max:100',
                'character.age' => 'nullable|max:100',
                'character.description' => 'required|string|min:5',
                'character.importance' => 'nullable|string|max:100',
                'style' => 'nullable|string|max:100',
                'project_id' => 'nullable|integer|exists:adstory_projects,id',
                'character_id' => 'nullable|integer',
            ]);

            $character = $this->normalizeCharacterInput($validated['character']);
            $characterId = $this->sanitizeAssetId($character['id']);

            $promptBundle = $this->contentService->buildCharacterImagePromptBundle(
                character: $character,
                style: $validated['style'] ?? null,
            );

            $imageBase64 = $this->geminiService->generateImage(
                $promptBundle['prompt'],
                $promptBundle['negative_prompt'],
            );
            $imageData = base64_decode($imageBase64, true);

            if ($imageData === false) {
                throw new RuntimeException('Gemini image generation failed: invalid base64 image data.');
            }

            $storagePath = isset($validated['project_id'])
                ? "adstory/projects/{$validated['project_id']}/characters/{$characterId}/hero/{$this->characterAssetService->uniqueStorageSuffix()}.png"
                : "adstory/temp/characters/{$characterId}.png";

            Storage::disk('public')->put($storagePath, $imageData);

            $imageUrl = $this->publicStorageUrl($request, $storagePath);

            $response = [
                'success' => true,
                'image_url' => $imageUrl,
                'prompt' => $promptBundle['full_prompt'],
                'negative_prompt' => $promptBundle['negative_prompt'],
                'character' => [
                    'id' => $characterId,
                    'name' => $character['name'],
                    'image_url' => $imageUrl,
                ],
            ];

            if ($projectId = $this->resolveProjectId($validated)) {
                Log::info('Adstory generate-character-image: project_id received', [
                    'project_id' => $projectId,
                    'character_id' => $validated['character_id'] ?? $character['id'],
                ]);

                $project = AdstoryProject::query()->findOrFail($projectId);
                $savedCharacter = $this->characterService->findProjectCharacter($project, [
                    'character_id' => $validated['character_id'] ?? null,
                    'id' => $character['id'],
                    'name' => $character['name'],
                ]);

                if ($savedCharacter) {
                    $meta = is_array($savedCharacter->meta ?? null) ? $savedCharacter->meta : [];
                    $meta['last_image_prompt'] = $promptBundle['prompt'];
                    $meta['last_image_negative_prompt'] = $promptBundle['negative_prompt'];
                    $meta['last_image_generated_at'] = now()->toIso8601String();
                    $meta['image_storage_path'] = $storagePath;

                    $savedCharacter->image_url = $imageUrl;
                    $savedCharacter->image_status = 'completed';
                    $savedCharacter->status = 'completed';
                    $savedCharacter->generation_error = null;
                    $savedCharacter->prompt = $promptBundle['full_prompt'];
                    $savedCharacter->meta = $meta;
                    $savedCharacter->save();

                    $asset = $this->characterAssetService->ensureHeroAsset(
                        character: $savedCharacter,
                        imageUrl: $imageUrl,
                        storagePath: $storagePath,
                        prompt: $promptBundle['full_prompt'],
                    );

                    Log::info('Adstory generate-character-image: character image saved', [
                        'project_id' => $projectId,
                        'character_db_id' => $savedCharacter->id,
                        'asset_id' => $asset?->id,
                    ]);

                    $response['character'] = $savedCharacter->fresh(['assets'])->toApiArray();
                    $response['asset'] = $asset?->toApiArray();
                } else {
                    Log::info('Adstory generate-character-image: no matching character row found', [
                        'project_id' => $projectId,
                        'character_lookup_id' => $character['id'],
                        'character_name' => $character['name'],
                    ]);
                }
            }

            return response()->json($response);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'API key is not configured') ? 500 : 502;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            return ApiErrorResponder::fromThrowable(
                $e,
                'We could not generate the character image right now. Please try again in a moment.'
            );
        }
    }

    public function generateCharacterReference(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'character' => 'required|array',
                'character.id' => 'required|max:100',
                'character.name' => 'required|string|max:150',
                'character.role' => 'nullable|string|max:150',
                'character.gender' => 'nullable|string|max:100',
                'character.age' => 'nullable|max:100',
                'character.description' => 'required|string|min:5',
                'character.importance' => 'nullable|string|max:100',
                'reference_type' => 'required|string|max:100',
                'asset_type' => 'nullable|string|max:100',
                'title' => 'nullable|string|max:255',
                'style' => 'nullable|string|max:100',
                'project_id' => 'nullable|integer|exists:adstory_projects,id',
                'character_id' => 'nullable|integer',
            ]);

            $character = $this->normalizeCharacterInput($validated['character']);
            $characterId = $this->sanitizeAssetId($character['id']);
            $referenceType = $this->sanitizeAssetId($validated['reference_type']);
            $assetType = $validated['asset_type'] ?? $this->characterAssetService->mapReferenceTypeToAssetType($referenceType);
            $referenceTitle = $validated['title']
                ?? $this->characterService->referenceTypeTitle($referenceType);
            $assetTitle = $validated['title']
                ?? $this->characterAssetService->assetTypeTitle($assetType);

            $prompt = $this->buildCharacterReferencePrompt(
                character: $character,
                referenceType: $referenceType,
                referenceTitle: $referenceTitle,
                style: $validated['style'] ?? null,
            );

            $imageBase64 = $this->geminiService->generateImage($prompt);
            $imageData = base64_decode($imageBase64, true);

            if ($imageData === false) {
                throw new RuntimeException('Gemini image generation failed: invalid base64 image data.');
            }

            $storageSuffix = $this->characterAssetService->uniqueStorageSuffix();
            $storagePath = isset($validated['project_id'])
                ? "adstory/projects/{$validated['project_id']}/characters/{$characterId}/references/{$assetType}_{$storageSuffix}.png"
                : "adstory/temp/characters/{$characterId}/references/{$referenceType}.png";

            Storage::disk('public')->put($storagePath, $imageData);

            $imageUrl = $this->publicStorageUrl($request, $storagePath);
            $createdAt = now()->toIso8601String();

            $reference = [
                'type' => $referenceType,
                'reference_type' => $referenceType,
                'title' => $referenceTitle,
                'image_url' => $imageUrl,
                'prompt' => $prompt,
                'created_at' => $createdAt,
            ];

            $response = [
                'success' => true,
                'image_url' => $imageUrl,
                'reference' => $reference,
                'character' => [
                    'id' => $characterId,
                    'name' => $character['name'],
                ],
            ];

            if (isset($validated['project_id'])) {
                $project = AdstoryProject::query()->findOrFail($validated['project_id']);
                $savedCharacter = $this->characterService->findProjectCharacter($project, [
                    'character_id' => $validated['character_id'] ?? null,
                    'id' => $character['id'],
                    'name' => $character['name'],
                ]);

                if ($savedCharacter) {
                    $savedCharacter = $this->characterService->appendReference($savedCharacter, $reference);

                    $asset = $this->characterAssetService->createReferenceAsset(
                        character: $savedCharacter,
                        assetType: $assetType,
                        title: $assetTitle,
                        imageUrl: $imageUrl,
                        storagePath: $storagePath,
                        prompt: $prompt,
                        meta: ['reference_type' => $referenceType],
                    );

                    $response['character'] = $savedCharacter->fresh(['assets'])->toApiArray();
                    $response['asset'] = $asset->toApiArray();
                }
            }

            return response()->json($response);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'API key is not configured') ? 500 : 502;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            return ApiErrorResponder::fromThrowable(
                $e,
                'We could not generate the reference image right now. Please try again in a moment.'
            );
        }
    }

    public function generateEnvironmentImage(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'environment' => 'required|array',
                'environment.id' => 'required|max:100',
                'environment.name' => 'required|string|max:150',
                'environment.type' => 'nullable|string|max:100',
                'environment.time_of_day' => 'nullable|string|max:100',
                'environment.description' => 'required|string|min:5',
                'environment.mood' => 'nullable|string|max:150',
                'environment.importance' => 'nullable|string|max:100',
                'style' => 'nullable|string|max:100',
                'project_id' => 'nullable|integer|exists:adstory_projects,id',
                'environment_id' => 'nullable|integer',
            ]);

            $environment = $this->normalizeEnvironmentInput($validated['environment']);
            $environmentId = $this->sanitizeAssetId($environment['id']);

            $prompt = $this->buildEnvironmentImagePrompt(
                environment: $environment,
                style: $validated['style'] ?? null,
            );

            $imageBase64 = $this->geminiService->generateImage($prompt);
            $imageData = base64_decode($imageBase64, true);

            if ($imageData === false) {
                throw new RuntimeException('Gemini image generation failed: invalid base64 image data.');
            }

            $storageSuffix = $this->environmentAssetService->uniqueStorageSuffix();
            $storagePath = isset($validated['project_id'])
                ? "adstory/projects/{$validated['project_id']}/environments/{$environmentId}/hero/{$storageSuffix}.png"
                : "adstory/temp/environments/{$environmentId}.png";

            Storage::disk('public')->put($storagePath, $imageData);

            $imageUrl = $this->publicStorageUrl($request, $storagePath);

            $response = [
                'success' => true,
                'image_url' => $imageUrl,
                'environment' => [
                    'id' => $environmentId,
                    'name' => $environment['name'],
                    'image_url' => $imageUrl,
                ],
            ];

            if ($projectId = $this->resolveProjectId($validated)) {
                Log::info('Adstory generate-environment-image: project_id received', [
                    'project_id' => $projectId,
                    'environment_id' => $validated['environment_id'] ?? $environment['id'],
                ]);

                $project = AdstoryProject::query()->findOrFail($projectId);
                $savedEnvironment = $this->environmentService->findProjectEnvironment($project, [
                    'environment_id' => $validated['environment_id'] ?? null,
                    'id' => $environment['id'],
                    'name' => $environment['name'],
                ]);

                if ($savedEnvironment) {
                    $meta = $savedEnvironment->meta ?? [];
                    $meta['last_image_generated_at'] = now()->toIso8601String();
                    $meta['image_storage_path'] = $storagePath;

                    if (! empty($validated['style'])) {
                        $meta['last_image_style'] = $validated['style'];
                    }

                    $savedEnvironment->image_url = $imageUrl;
                    $savedEnvironment->image_status = 'completed';
                    $savedEnvironment->prompt = $prompt;
                    $savedEnvironment->meta = $meta;
                    $savedEnvironment->save();

                    $asset = $this->environmentAssetService->createHeroAsset(
                        environment: $savedEnvironment,
                        imageUrl: $imageUrl,
                        storagePath: $storagePath,
                        prompt: $prompt,
                        meta: $meta,
                    );

                    Log::info('Adstory generate-environment-image: environment image saved', [
                        'project_id' => $projectId,
                        'environment_db_id' => $savedEnvironment->id,
                        'asset_id' => $asset->id,
                        'images_generated' => 1,
                    ]);

                    $response['environment'] = $savedEnvironment->fresh(['assets'])->toApiArray();
                    $response['asset'] = $asset->toApiArray();
                } else {
                    Log::info('Adstory generate-environment-image: no matching environment row found', [
                        'project_id' => $projectId,
                        'environment_lookup_id' => $environment['id'],
                        'environment_name' => $environment['name'],
                    ]);
                }
            }

            return response()->json($response);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'API key is not configured') ? 500 : 502;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            return ApiErrorResponder::fromThrowable(
                $e,
                'We could not generate the environment image right now. Please try again in a moment.'
            );
        }
    }

    private function buildPrompt(string $story, ?string $style): string
    {
        $styleInstruction = $style
            ? "The video storyboard style is: {$style}. Reflect this style in tone, pacing, and visual direction."
            : 'Use a cinematic storyboard-friendly style suitable for video production.';

        return <<<PROMPT
You are a professional video scriptwriter. Convert the following story into a production-ready video script.

Rules:
- Do not change the meaning of the story.
- Expand the story into a production-ready script.
- Include narration, dialogue where needed, scene direction, mood, and visual direction.
- Keep it clear for later screenplay, scenes, and shots generation.
- Do not generate discrete scenes yet (no numbered scene breakdowns).
- Do not generate shot lists yet.
- Do not generate character profiles yet.
- Do not generate environment descriptions as separate lists yet.
- Return plain text only.
- No markdown tables.
- No JSON.
- No markdown formatting.

{$styleInstruction}

Story:
{$story}
PROMPT;
    }

    private function buildScreenplayPrompt(string $script, ?string $style): string
    {
        $styleInstruction = $style
            ? "The video storyboard style is: {$style}. Reflect this style in tone, pacing, and visual direction."
            : 'Use a cinematic storyboard-friendly style suitable for video production.';

        return <<<PROMPT
You are a professional screenwriter. Convert the following video script into a professional screenplay format.

Rules:
- Do not change the story meaning.
- Do not add new plot points.
- Do not remove important details.
- Preserve characters, dialogue, setting, tone, and sequence.
- Format into screenplay style.
- Include scene headings where appropriate.
- Include action lines.
- Include character dialogue where needed.
- Keep it clear for later scene extraction.
- Do not generate a scene list yet.
- Do not generate shots yet.
- Do not generate a characters list yet.
- Return plain text only.
- No markdown tables.
- No JSON.
- No markdown formatting.

{$styleInstruction}

Script:
{$script}
PROMPT;
    }

    private function buildScenesPrompt(string $screenplay, ?string $style): string
    {
        $styleInstruction = $style
            ? "The video storyboard style is: {$style}. Reflect this style in mood and environment descriptions."
            : 'Use a cinematic storyboard-friendly style suitable for video production.';

        return <<<PROMPT
You are a professional production breakdown artist. Analyze the following screenplay and divide it into clear production scenes.

Rules:
- Do not change the story meaning.
- Do not add new plot points.
- Preserve the original order.
- Each scene should represent one clear story moment or location/time change.
- Return only valid JSON.
- No markdown.
- No explanation.
- No text before or after JSON.

{$styleInstruction}

JSON format must be exactly:

[
  {
    "scene_number": 1,
    "title": "Scene title",
    "location": "Main location",
    "time_of_day": "Day / Night / Sunset / Morning / Unknown",
    "description": "Short scene description",
    "mood": "Scene mood",
    "characters": ["Character 1", "Character 2"],
    "environment": "Environment description"
  }
]

Screenplay:
{$screenplay}
PROMPT;
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenes
     */
    private function buildShotsPrompt(array $scenes, ?string $style): string
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

    private function buildExtractCharactersPrompt(string $screenplay): string
    {
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
{$screenplay}
PROMPT;
    }

    private function buildExtractEnvironmentsPrompt(string $screenplay): string
    {
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
PROMPT;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseScenesJson(string $text): array
    {
        return $this->parseGeminiJsonArray($text, 'scenes');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseShotsJson(string $text): array
    {
        return $this->parseGeminiJsonArray($text, 'shots');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseCharactersJson(string $text): array
    {
        return $this->parseGeminiJsonArray($text, 'characters');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseEnvironmentsJson(string $text): array
    {
        return $this->parseGeminiJsonArray($text, 'environments');
    }

    /**
     * @param  array<int, array<string, mixed>>  $characters
     * @return array<int, array<string, string>>
     */
    private function normalizeCharacters(array $characters): array
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
    private function normalizeEnvironments(array $environments): array
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

    /**
     * @param  array<string, mixed>  $character
     * @deprecated Use AdstoryGeminiContentService::buildCharacterImagePromptBundle()
     */
    private function buildCharacterImagePrompt(array $character, ?string $style): string
    {
        return app(AdstoryGeminiContentService::class)->buildCharacterImagePrompt($character, $style);
    }

    /**
     * @param  array<string, mixed>  $character
     */
    private function buildCharacterReferencePrompt(
        array $character,
        string $referenceType,
        string $referenceTitle,
        ?string $style,
    ): string {
        $visualStyle = $style ?? 'Cinematic Realistic';
        $name = $character['name'];
        $role = $character['role'] ?? 'Unknown role';
        $gender = $character['gender'] ?? 'Unknown';
        $age = $character['age'] ?? 'Unknown';
        $description = $character['description'];
        $importance = $character['importance'] ?? 'Unknown';
        $poseInstruction = $this->referencePoseInstruction($referenceType);

        return <<<PROMPT
Generate a character reference image for storyboard production.

Reference pose: {$referenceTitle}
Pose instructions: {$poseInstruction}

Character details:
- Name: {$name}
- Role: {$role}
- Gender: {$gender}
- Age: {$age}
- Importance: {$importance}
- Description: {$description}

Rules:
- Single character only.
- Neutral clean background.
- {$poseInstruction}
- Clear face and outfit details.
- Visual style: {$visualStyle}.
- No text.
- No watermark.
- No extra people.
- No distorted hands.
- No duplicated body parts.
- Preserve character age, gender, role, and description.
PROMPT;
    }

    private function referencePoseInstruction(string $referenceType): string
    {
        return match ($referenceType) {
            'front_view' => 'Front-facing pose with full visibility of face and outfit.',
            'back_view' => 'Back-facing pose showing outfit and silhouette from behind.',
            'left_profile' => 'Left profile view showing side of face and body.',
            'right_profile' => 'Right profile view showing side of face and body.',
            'standing_full_body' => 'Standing full body shot from head to toe.',
            'sitting' => 'Sitting pose with natural posture.',
            'with_stick' => 'Character holding a stick or walking stick naturally.',
            'talking' => 'Character talking or explaining with natural gesture.',
            'pointing' => 'Character pointing clearly in one direction.',
            'looking_up' => 'Character looking upward with natural head tilt.',
            'looking_down' => 'Character looking downward with natural head tilt.',
            'laughing' => 'Character laughing with joyful expression.',
            'crying' => 'Character showing sadness or crying expression.',
            'fighting' => 'Character in an angry or fighting stance.',
            'thinking' => 'Character in a thoughtful pose.',
            default => 'Pose matching the reference type: '.str_replace('_', ' ', $referenceType).'.',
        };
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function buildEnvironmentImagePrompt(array $environment, ?string $style): string
    {
        $visualStyle = $style ?? 'Cinematic Realistic';
        $name = $environment['name'];
        $type = $environment['type'] ?? 'Unknown';
        $timeOfDay = $environment['time_of_day'] ?? 'Unknown';
        $description = $environment['description'];
        $mood = $environment['mood'] ?? 'Unknown';
        $importance = $environment['importance'] ?? 'Unknown';

        return <<<PROMPT
Generate a cinematic environment reference image.

Environment details:
- Name: {$name}
- Type: {$type}
- Time of day: {$timeOfDay}
- Mood: {$mood}
- Importance: {$importance}
- Description: {$description}

Rules:
- Environment only.
- No people.
- No characters.
- No text.
- No watermark.
- No UI elements.
- No logos.
- Show clear location layout.
- Show lighting and mood clearly.
- Match the environment name, type, time of day, mood, and description.
- Visual style: {$visualStyle}.
PROMPT;
    }

    private function sanitizeAssetId(string $id): string
    {
        $sanitized = Str::slug((string) $id, '_');

        if ($sanitized === '') {
            throw new RuntimeException('Invalid asset id provided.');
        }

        return $sanitized;
    }

    private function publicStorageUrl(Request $request, string $storagePath): string
    {
        $normalizedPath = ltrim(str_replace('\\', '/', $storagePath), '/');

        return rtrim($request->getSchemeAndHttpHost(), '/').'/storage/'.$normalizedPath;
    }

    /**
     * @param  array<string, mixed>  $character
     * @return array<string, string>
     */
    private function normalizeCharacterInput(array $character): array
    {
        $id = $character['id'] ?? null;

        if ($id === null || $id === '') {
            throw new RuntimeException('Character id is required.');
        }

        return [
            'id' => (string) $id,
            'name' => (string) ($character['name'] ?? ''),
            'role' => (string) ($character['role'] ?? ''),
            'gender' => (string) ($character['gender'] ?? ''),
            'age' => (string) ($character['age'] ?? ''),
            'description' => (string) ($character['description'] ?? ''),
            'importance' => (string) ($character['importance'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $environment
     * @return array<string, string>
     */
    private function normalizeEnvironmentInput(array $environment): array
    {
        $id = $environment['id'] ?? null;

        if ($id === null || $id === '') {
            throw new RuntimeException('Environment id is required.');
        }

        return [
            'id' => (string) $id,
            'name' => (string) ($environment['name'] ?? ''),
            'type' => (string) ($environment['type'] ?? ''),
            'time_of_day' => (string) ($environment['time_of_day'] ?? ''),
            'description' => (string) ($environment['description'] ?? ''),
            'mood' => (string) ($environment['mood'] ?? ''),
            'importance' => (string) ($environment['importance'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveProjectId(array $validated): ?int
    {
        if (! array_key_exists('project_id', $validated) || $validated['project_id'] === null) {
            return null;
        }

        return (int) $validated['project_id'];
    }
}
