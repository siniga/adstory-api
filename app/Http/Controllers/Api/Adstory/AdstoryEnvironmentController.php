<?php

namespace App\Http\Controllers\Api\Adstory;

use App\Http\Controllers\Controller;
use App\Models\AdstoryAiTask;
use App\Models\AdstoryEnvironment;
use App\Models\AdstoryProject;
use App\Services\Adstory\AdstoryAiTaskOrchestratorService;
use App\Services\Adstory\AdstoryAiTaskProgressService;
use App\Services\Adstory\AdstoryEnvironmentAssetService;
use App\Services\Adstory\AdstoryEnvironmentGenerationService;
use App\Services\Adstory\AdstoryEnvironmentService;
use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdstoryEnvironmentController extends Controller
{
    public function __construct(
        private readonly AdstoryEnvironmentService $environmentService,
        private readonly AdstoryEnvironmentGenerationService $environmentGenerationService,
        private readonly AdstoryEnvironmentAssetService $environmentAssetService,
        private readonly AdstoryAiTaskOrchestratorService $taskOrchestrator,
        private readonly AdstoryAiTaskProgressService $progressService,
        private readonly GeminiService $geminiService,
    ) {}

    public function index(AdstoryProject $project): JsonResponse
    {
        $environments = $project->environments()
            ->orderBy('order_index')
            ->orderBy('id')
            ->get()
            ->map(fn (AdstoryEnvironment $environment) => $environment->toApiArray())
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'environments' => $environments,
        ]);
    }

    public function startGeneration(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->environmentGenerationService->startGeneration(
                project: $project,
                style: $validated['style'] ?? null,
            );

            $statusCode = ($result['started'] ?? false) ? 202 : 200;

            return response()->json($result, $statusCode);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while starting environment generation.');
        }
    }

    public function startExtraction(AdstoryProject $project): JsonResponse
    {
        return $this->startGeneration(request(), $project);
    }

    public function startImageGeneration(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->environmentGenerationService->startImageGeneration(
                project: $project,
                style: $validated['style'] ?? null,
            );

            $statusCode = ($result['started'] ?? false) ? 202 : 200;

            return response()->json($result, $statusCode);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while starting environment image generation.');
        }
    }

    public function regenerateImage(Request $request, AdstoryProject $project, AdstoryEnvironment $environment): JsonResponse
    {
        if (! $this->environmentBelongsToProject($environment, $project)) {
            return $this->notFoundResponse('Environment not found for this project.');
        }

        try {
            $validated = $request->validate([
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->environmentGenerationService->regenerateEnvironmentImage(
                project: $project,
                environment: $environment,
                style: $validated['style'] ?? null,
            );

            return response()->json($result, 202);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while regenerating the environment image.');
        }
    }

    public function regenerateAllImages(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->environmentGenerationService->regenerateAllEnvironmentImages(
                project: $project,
                style: $validated['style'] ?? null,
            );

            return response()->json($result, 202);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while regenerating environment images.');
        }
    }

    public function progress(AdstoryProject $project): JsonResponse
    {
        return response()->json(
            $this->environmentGenerationService->getProgress($project)
        );
    }

    public function resumeGeneration(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'retry_failed' => 'sometimes|boolean',
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->environmentGenerationService->resumeGeneration(
                project: $project,
                retryFailed: (bool) ($validated['retry_failed'] ?? false),
                style: $validated['style'] ?? null,
            );

            return response()->json($result, 202);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while resuming environment generation.');
        }
    }

    public function retry(Request $request, AdstoryProject $project, AdstoryEnvironment $environment): JsonResponse
    {
        if (! $this->environmentBelongsToProject($environment, $project)) {
            return $this->notFoundResponse('Environment not found for this project.');
        }

        try {
            $validated = $request->validate([
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->environmentGenerationService->retryEnvironment(
                project: $project,
                environment: $environment,
                style: $validated['style'] ?? null,
            );

            return response()->json($result, 202);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while retrying environment image generation.');
        }
    }

    public function store(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate($this->environmentItemRules());

            $orderIndex = $validated['order_index']
                ?? (($project->environments()->max('order_index') ?? -1) + 1);

            $environment = AdstoryEnvironment::query()->create(
                $this->environmentService->mapEnvironmentAttributes(
                    projectId: $project->id,
                    data: array_merge($validated, ['order_index' => $orderIndex]),
                    orderIndex: $orderIndex,
                )
            );

            return response()->json([
                'success' => true,
                'environment' => $environment->toApiArray(),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while creating the environment.');
        }
    }

    public function update(Request $request, AdstoryProject $project, AdstoryEnvironment $environment): JsonResponse
    {
        if (! $this->environmentBelongsToProject($environment, $project)) {
            return $this->notFoundResponse('Environment not found for this project.');
        }

        try {
            $validated = $request->validate($this->environmentItemRules(required: false));

            $attributes = $this->environmentService->mapEnvironmentAttributes(
                projectId: $project->id,
                data: array_merge($environment->toApiArray(), $validated),
                orderIndex: $validated['order_index'] ?? $environment->order_index,
            );

            unset($attributes['adstory_project_id']);
            $environment->fill($attributes);
            $environment->save();

            return response()->json([
                'success' => true,
                'environment' => $environment->fresh()->toApiArray(),
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while updating the environment.');
        }
    }

    public function destroy(AdstoryProject $project, AdstoryEnvironment $environment): JsonResponse
    {
        if (! $this->environmentBelongsToProject($environment, $project)) {
            return $this->notFoundResponse('Environment not found for this project.');
        }

        $environment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Environment deleted successfully.',
        ]);
    }

    public function bulkReplace(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'environments' => 'required|array',
                ...$this->environmentArrayRules(),
            ]);

            $environments = $this->environmentService->replaceProjectEnvironments(
                project: $project,
                environmentsData: $validated['environments'],
            );

            return response()->json([
                'success' => true,
                'environments' => $environments,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while saving environments.');
        }
    }

    public function generateReference(Request $request, AdstoryProject $project, AdstoryEnvironment $environment): JsonResponse
    {
        if (! $this->environmentBelongsToProject($environment, $project)) {
            return $this->notFoundResponse('Environment not found for this project.');
        }

        try {
            $validated = $request->validate([
                'asset_type' => 'nullable|string|max:100',
                'title' => 'nullable|string|max:255',
                'prompt_modifier' => 'nullable|string|max:500',
                'style' => 'nullable|string|max:100',
            ]);

            $assetType = $validated['asset_type'] ?? 'wide';
            $assetTitle = $validated['title'] ?? $this->environmentAssetService->assetTypeTitle($assetType);
            $promptModifier = $validated['prompt_modifier'] ?? null;
            $visualStyle = $validated['style'] ?? $project->visual_style ?? 'Cinematic Realistic';

            $prompt = $this->buildEnvironmentReferencePrompt(
                environment: $environment,
                assetType: $assetType,
                assetTitle: $assetTitle,
                promptModifier: $promptModifier,
                style: $visualStyle,
            );

            Log::info('Adstory generate-environment-reference: starting', [
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'asset_type' => $assetType,
            ]);

            $imageBase64 = $this->geminiService->generateImage($prompt);
            $imageData = base64_decode($imageBase64, true);

            if ($imageData === false) {
                throw new RuntimeException('Gemini image generation failed: invalid base64 image data.');
            }

            $storageSuffix = $this->environmentAssetService->uniqueStorageSuffix();
            $storagePath = "adstory/projects/{$project->id}/environments/{$environment->id}/references/{$assetType}_{$storageSuffix}.png";

            Storage::disk('public')->put($storagePath, $imageData);

            $imageUrl = $this->publicStorageUrl($request, $storagePath);

            $asset = $this->environmentAssetService->createReferenceAsset(
                environment: $environment,
                assetType: $assetType,
                title: $assetTitle,
                imageUrl: $imageUrl,
                storagePath: $storagePath,
                prompt: $prompt,
                meta: ['prompt_modifier' => $promptModifier],
            );

            Log::info('Adstory generate-environment-reference: saved', [
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'asset_id' => $asset->id,
            ]);

            return response()->json([
                'success' => true,
                'environment' => $environment->fresh(['assets'])->toApiArray(),
                'asset' => $asset->toApiArray(),
                'message' => 'Environment reference generated successfully.',
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'API key is not configured') ? 500 : 502;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (Throwable $e) {
            Log::error('Adstory generate-environment-reference: failed', [
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'message' => $e->getMessage(),
            ]);

            return $this->unexpectedErrorResponse('An unexpected error occurred while generating the environment reference.');
        }
    }

    private function buildEnvironmentReferencePrompt(
        AdstoryEnvironment $environment,
        string $assetType,
        string $assetTitle,
        ?string $promptModifier,
        string $style,
    ): string {
        $modifierLine = $promptModifier ? "\nAdditional direction: {$promptModifier}" : '';

        return <<<PROMPT
Generate a cinematic environment reference image for storyboard production.

Reference variant: {$assetTitle}
Asset type: {$assetType}

Environment details:
- Name: {$environment->name}
- Type: {$environment->type}
- Time of day: {$environment->time_of_day}
- Description: {$environment->description}
- Mood: {$environment->mood}
{$modifierLine}

Rules:
- Environment only.
- No people.
- No characters.
- No text.
- No watermark.
- Show clear location layout.
- Match the reference variant and visual style: {$style}.
PROMPT;
    }

    private function publicStorageUrl(Request $request, string $storagePath): string
    {
        $normalizedPath = ltrim(str_replace('\\', '/', $storagePath), '/');

        return rtrim($request->getSchemeAndHttpHost(), '/').'/storage/'.$normalizedPath;
    }

    /**
     * @return array<string, string>
     */
    private function environmentItemRules(bool $required = false): array
    {
        return [
            'id' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:100',
            'time_of_day' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'appearance' => 'nullable|string',
            'lighting' => 'nullable|string|max:255',
            'mood' => 'nullable|string|max:255',
            'image_url' => 'nullable|string',
            'image_status' => 'nullable|string|max:100',
            'prompt' => 'nullable|string',
            'references' => 'nullable|array',
            'order_index' => 'nullable|integer',
            'status' => 'nullable|string|max:100',
            'meta' => 'nullable|array',
            'importance' => 'nullable|string|max:255',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function environmentArrayRules(): array
    {
        return [
            'environments.*.id' => 'nullable|string|max:100',
            'environments.*.name' => 'nullable|string|max:255',
            'environments.*.type' => 'nullable|string|max:100',
            'environments.*.time_of_day' => 'nullable|string|max:100',
            'environments.*.description' => 'nullable|string',
            'environments.*.appearance' => 'nullable|string',
            'environments.*.lighting' => 'nullable|string|max:255',
            'environments.*.mood' => 'nullable|string|max:255',
            'environments.*.image_url' => 'nullable|string',
            'environments.*.image_status' => 'nullable|string|max:100',
            'environments.*.prompt' => 'nullable|string',
            'environments.*.references' => 'nullable|array',
            'environments.*.order_index' => 'nullable|integer',
            'environments.*.status' => 'nullable|string|max:100',
            'environments.*.meta' => 'nullable|array',
            'environments.*.importance' => 'nullable|string|max:255',
        ];
    }

    private function environmentBelongsToProject(AdstoryEnvironment $environment, AdstoryProject $project): bool
    {
        return $environment->adstory_project_id === $project->id;
    }

    private function validationErrorResponse(ValidationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->validator->errors()->first(),
        ], 422);
    }

    private function notFoundResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 404);
    }

    private function unexpectedErrorResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 500);
    }
}
