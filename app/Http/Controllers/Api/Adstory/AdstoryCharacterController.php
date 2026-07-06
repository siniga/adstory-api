<?php

namespace App\Http\Controllers\Api\Adstory;

use App\Http\Controllers\Controller;
use App\Models\AdstoryCharacter;
use App\Models\AdstoryProject;
use App\Services\Adstory\AdstoryAiTaskOrchestratorService;
use App\Services\Adstory\AdstoryCharacterGenerationService;
use App\Services\Adstory\AdstoryCharacterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdstoryCharacterController extends Controller
{
    public function __construct(
        private readonly AdstoryCharacterService $characterService,
        private readonly AdstoryCharacterGenerationService $characterGenerationService,
        private readonly AdstoryAiTaskOrchestratorService $taskOrchestrator,
    ) {}

    public function index(AdstoryProject $project): JsonResponse
    {
        $characters = $project->characters()
            ->orderBy('order_index')
            ->orderBy('id')
            ->get()
            ->map(fn (AdstoryCharacter $character) => $character->toApiArray())
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'characters' => $characters,
        ]);
    }

    public function startGeneration(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->characterGenerationService->startGeneration(
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
            return $this->unexpectedErrorResponse('An unexpected error occurred while starting character generation.');
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

            $result = $this->taskOrchestrator->startCharacterImageGeneration(
                project: $project,
                style: $validated['style'] ?? null,
            );

            return response()->json($result, 202);
        } catch (RuntimeException $e) {
            $status = match (true) {
                str_contains($e->getMessage(), 'already running') => 409,
                str_contains($e->getMessage(), 'No characters') => 422,
                default => 422,
            };

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while starting character image generation.');
        }
    }

    public function progress(AdstoryProject $project): JsonResponse
    {
        return response()->json(
            $this->characterGenerationService->getProgress($project)
        );
    }

    public function resumeGeneration(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'retry_failed' => 'sometimes|boolean',
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->characterGenerationService->resumeGeneration(
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
            return $this->unexpectedErrorResponse('An unexpected error occurred while resuming character generation.');
        }
    }

    public function store(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate($this->characterItemRules());

            $orderIndex = $validated['order_index']
                ?? (($project->characters()->max('order_index') ?? -1) + 1);

            $character = AdstoryCharacter::query()->create(
                $this->characterService->mapCharacterAttributes(
                    projectId: $project->id,
                    data: array_merge($validated, ['order_index' => $orderIndex]),
                    orderIndex: $orderIndex,
                )
            );

            return response()->json([
                'success' => true,
                'character' => $character->toApiArray(),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while creating the character.');
        }
    }

    public function update(Request $request, AdstoryProject $project, AdstoryCharacter $character): JsonResponse
    {
        if (! $this->characterBelongsToProject($character, $project)) {
            return $this->notFoundResponse('Character not found for this project.');
        }

        try {
            $validated = $request->validate($this->characterItemRules(required: false));

            $attributes = $this->characterService->mapCharacterAttributes(
                projectId: $project->id,
                data: array_merge($character->toApiArray(), $validated),
                orderIndex: $validated['order_index'] ?? $character->order_index,
            );

            unset($attributes['adstory_project_id']);
            $character->fill($attributes);
            $character->save();

            return response()->json([
                'success' => true,
                'character' => $character->fresh()->toApiArray(),
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while updating the character.');
        }
    }

    public function destroy(AdstoryProject $project, AdstoryCharacter $character): JsonResponse
    {
        if (! $this->characterBelongsToProject($character, $project)) {
            return $this->notFoundResponse('Character not found for this project.');
        }

        $character->delete();

        return response()->json([
            'success' => true,
            'message' => 'Character deleted successfully.',
        ]);
    }

    public function bulkReplace(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'characters' => 'required|array',
                ...$this->characterArrayRules(),
            ]);

            $characters = $this->characterService->replaceProjectCharacters(
                project: $project,
                charactersData: $validated['characters'],
            );

            return response()->json([
                'success' => true,
                'characters' => $characters,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while saving characters.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function characterItemRules(bool $required = false): array
    {
        return [
            'id' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:255',
            'role' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'personality' => 'nullable|string',
            'appearance' => 'nullable|string',
            'wardrobe' => 'nullable|string',
            'age' => 'nullable|string|max:100',
            'gender' => 'nullable|string|max:100',
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
    private function characterArrayRules(): array
    {
        return [
            'characters.*.id' => 'nullable|string|max:100',
            'characters.*.name' => 'nullable|string|max:255',
            'characters.*.role' => 'nullable|string|max:255',
            'characters.*.description' => 'nullable|string',
            'characters.*.personality' => 'nullable|string',
            'characters.*.appearance' => 'nullable|string',
            'characters.*.wardrobe' => 'nullable|string',
            'characters.*.age' => 'nullable|string|max:100',
            'characters.*.gender' => 'nullable|string|max:100',
            'characters.*.image_url' => 'nullable|string',
            'characters.*.image_status' => 'nullable|string|max:100',
            'characters.*.prompt' => 'nullable|string',
            'characters.*.references' => 'nullable|array',
            'characters.*.order_index' => 'nullable|integer',
            'characters.*.status' => 'nullable|string|max:100',
            'characters.*.meta' => 'nullable|array',
            'characters.*.importance' => 'nullable|string|max:255',
        ];
    }

    private function characterBelongsToProject(AdstoryCharacter $character, AdstoryProject $project): bool
    {
        return $character->adstory_project_id === $project->id;
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
