<?php

namespace App\Http\Controllers\Api\Adstory;

use App\Http\Controllers\Controller;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Services\Adstory\AdstorySceneGenerationService;
use App\Services\Adstory\AdstorySceneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use App\Support\ApiErrorResponder;

class AdstorySceneController extends Controller
{
    /**
     * Scene environment accepts a full descriptive prompt (unlimited length),
     * e.g. a rich cinematic location description from Gemini — not a short label.
     *
     * TODO: Future refactor will use environment_id referencing the Environment library.
     */
    public function __construct(
        private readonly AdstorySceneService $sceneService,
        private readonly AdstorySceneGenerationService $sceneGenerationService,
    ) {}

    public function index(AdstoryProject $project): JsonResponse
    {
        $scenes = $project->scenes()
            ->orderBy('order_index')
            ->orderBy('id')
            ->get()
            ->map(fn (AdstoryScene $scene) => $scene->toApiArray())
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'scenes' => $scenes,
        ]);
    }

    public function startGeneration(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->sceneGenerationService->startGeneration(
                project: $project,
                visualStyle: $validated['style'] ?? null,
            );

            return response()->json(array_merge([
                'success' => true,
            ], $result), 202);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return ApiErrorResponder::fromThrowable(
                $e,
                'We could not start scene generation right now. Please try again in a few minutes.'
            );
        }
    }

    public function progress(AdstoryProject $project): JsonResponse
    {
        return response()->json(
            $this->sceneGenerationService->getProgress($project)
        );
    }

    public function retry(AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        if (! $this->sceneBelongsToProject($scene, $project)) {
            return $this->notFoundResponse('Scene not found for this project.');
        }

        try {
            $progress = $this->sceneGenerationService->retryScene($project, $scene);

            return response()->json($progress);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Adstory scene-generation: retry failed', [
                'project_id' => $project->id,
                'scene_id' => $scene->id,
                'message' => $e->getMessage(),
            ]);

            return $this->unexpectedErrorResponse('An unexpected error occurred while retrying scene generation.');
        }
    }

    public function retryFailed(AdstoryProject $project): JsonResponse
    {
        try {
            $progress = $this->sceneGenerationService->retryFailed($project);

            return response()->json($progress);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Adstory scene-generation: retry-failed failed', [
                'project_id' => $project->id,
                'message' => $e->getMessage(),
            ]);

            return $this->unexpectedErrorResponse('An unexpected error occurred while retrying failed scenes.');
        }
    }

    public function resumeGeneration(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'retry_failed' => 'sometimes|boolean',
            ]);

            $progress = $this->sceneGenerationService->resumeGeneration(
                project: $project,
                retryFailed: (bool) ($validated['retry_failed'] ?? false),
            );

            return response()->json([
                'success' => true,
                'message' => 'Scene generation resumed.',
                'progress' => $progress,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            Log::error('Adstory scene-generation: resume failed', [
                'project_id' => $project->id,
                'message' => $e->getMessage(),
            ]);

            return $this->unexpectedErrorResponse('An unexpected error occurred while resuming scene generation.');
        }
    }

    public function pauseGeneration(AdstoryProject $project): JsonResponse
    {
        try {
            $progress = $this->sceneGenerationService->pauseGeneration($project);

            return response()->json([
                'success' => true,
                'message' => 'Scene generation paused.',
                'progress' => $progress,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Adstory scene-generation: pause failed', [
                'project_id' => $project->id,
                'message' => $e->getMessage(),
            ]);

            return $this->unexpectedErrorResponse('An unexpected error occurred while pausing scene generation.');
        }
    }

    public function cancelGeneration(AdstoryProject $project): JsonResponse
    {
        try {
            $progress = $this->sceneGenerationService->cancelGeneration($project);

            return response()->json([
                'success' => true,
                'message' => 'Scene generation cancelled.',
                'progress' => $progress,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Adstory scene-generation: cancel failed', [
                'project_id' => $project->id,
                'message' => $e->getMessage(),
            ]);

            return $this->unexpectedErrorResponse('An unexpected error occurred while cancelling scene generation.');
        }
    }

    public function restartGeneration(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'delete_existing' => 'sometimes|boolean',
            ]);

            $result = $this->sceneGenerationService->restartGeneration(
                project: $project,
                deleteExisting: (bool) ($validated['delete_existing'] ?? false),
            );

            return response()->json([
                'success' => true,
                'message' => ($validated['delete_existing'] ?? false)
                    ? 'Scene generation restarted from beginning.'
                    : 'Scene generation restarted for unfinished scenes.',
                'project' => $result['project'] ?? null,
                'scenes' => $result['scenes'] ?? null,
                'tasks' => $result['tasks'] ?? null,
                'progress' => $result['progress'] ?? $result,
            ], 202);
        } catch (RuntimeException $e) {
            $status = match (true) {
                str_contains($e->getMessage(), 'screenplay') => 422,
                str_contains($e->getMessage(), 'already running') => 409,
                str_contains($e->getMessage(), 'API key is not configured') => 500,
                default => 422,
            };

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            Log::error('Adstory scene-generation: restart failed', [
                'project_id' => $project->id,
                'message' => $e->getMessage(),
            ]);

            return $this->unexpectedErrorResponse('An unexpected error occurred while restarting scene generation.');
        }
    }

    public function store(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate($this->sceneboardCreateRules());

            $scene = $this->sceneService->insertSceneboardScene(
                project: $project,
                data: $validated,
                position: $validated['position'],
                referenceSceneId: isset($validated['reference_scene_id'])
                    ? (int) $validated['reference_scene_id']
                    : null,
            );

            return response()->json([
                'success' => true,
                'scene' => $scene->toApiArray(),
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while creating the scene.');
        }
    }

    public function update(Request $request, AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        if (! $this->sceneBelongsToProject($scene, $project)) {
            return $this->notFoundResponse('Scene not found for this project.');
        }

        try {
            $validated = $request->validate($this->sceneboardUpdateRules());

            $result = $this->sceneService->updateSceneboardScene(
                project: $project,
                scene: $scene,
                data: $validated,
            );

            $response = [
                'success' => true,
                'scene' => $result['scene']->toApiArray(),
            ];

            if ($result['warning'] !== null) {
                $response['warning'] = $result['warning'];
            }

            return response()->json($response);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while updating the scene.');
        }
    }

    public function destroy(Request $request, AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        if (! $this->sceneBelongsToProject($scene, $project)) {
            return $this->notFoundResponse('Scene not found for this project.');
        }

        try {
            $validated = $request->validate([
                'force' => 'sometimes|boolean',
            ]);

            $this->sceneService->deleteSceneboardScene(
                project: $project,
                scene: $scene,
                force: (bool) ($validated['force'] ?? false),
            );

            return response()->json([
                'success' => true,
                'message' => 'Scene deleted successfully.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while deleting the scene.');
        }
    }

    public function bulkReplace(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'scenes' => 'required|array',
                'force' => 'sometimes|boolean',
                ...$this->sceneArrayRules(),
            ]);

            $scenes = $this->sceneService->replaceProjectScenes(
                project: $project,
                scenesData: $validated['scenes'],
                visualStyle: $validated['visual_style'] ?? null,
                force: (bool) ($validated['force'] ?? $request->input('force', false)),
            );

            return response()->json([
                'success' => true,
                'scenes' => $scenes,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while saving scenes.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function sceneboardCreateRules(): array
    {
        return [
            'position' => 'required|string|in:before,after,end',
            'reference_scene_id' => 'required_if:position,before,after|nullable|integer',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'time_of_day' => 'nullable|string|max:100',
            'mood' => 'nullable|string',
            'visual_style' => 'nullable|string',
            'environment' => 'nullable|string',
            'status' => 'nullable|string|in:completed,draft',
            'meta' => 'nullable|array',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sceneboardUpdateRules(): array
    {
        return [
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'time_of_day' => 'nullable|string|max:100',
            'mood' => 'nullable|string',
            'visual_style' => 'nullable|string',
            'environment' => 'nullable|string',
            'meta' => 'nullable|array',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sceneItemRules(bool $required = true): array
    {
        return [
            'scene_number' => 'nullable|integer',
            'title' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'time_of_day' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'screenplay_excerpt' => 'nullable|string',
            'mood' => 'nullable|string',
            'visual_style' => 'nullable|string',
            'order_index' => 'nullable|integer',
            'status' => 'nullable|string|max:100',
            'meta' => 'nullable|array',
            'characters' => 'nullable|array',
            'environment' => 'nullable|string',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sceneArrayRules(): array
    {
        return [
            'visual_style' => 'nullable|string',
            'scenes.*.id' => 'nullable|integer',
            'scenes.*.scene_number' => 'nullable|integer',
            'scenes.*.title' => 'nullable|string|max:255',
            'scenes.*.slug' => 'nullable|string|max:255',
            'scenes.*.location' => 'nullable|string|max:255',
            'scenes.*.time_of_day' => 'nullable|string|max:100',
            'scenes.*.description' => 'nullable|string',
            'scenes.*.screenplay_excerpt' => 'nullable|string',
            'scenes.*.mood' => 'nullable|string',
            'scenes.*.visual_style' => 'nullable|string',
            'scenes.*.order_index' => 'nullable|integer',
            'scenes.*.status' => 'nullable|string|max:100',
            'scenes.*.meta' => 'nullable|array',
            'scenes.*.characters' => 'nullable|array',
            'scenes.*.environment' => 'nullable|string',
        ];
    }

    private function sceneBelongsToProject(AdstoryScene $scene, AdstoryProject $project): bool
    {
        return $scene->adstory_project_id === $project->id;
    }

    private function validationErrorResponse(ValidationException $e): JsonResponse
    {
        return ApiErrorResponder::error(
            message: $e->validator->errors()->first(),
            status: 422,
            code: 'validation_failed',
            extra: ['errors' => $e->errors()],
        );
    }

    private function notFoundResponse(string $message): JsonResponse
    {
        return ApiErrorResponder::error($message, 404, 'not_found');
    }

    private function unexpectedErrorResponse(string $message): JsonResponse
    {
        return ApiErrorResponder::error($message, 500, 'unexpected_error');
    }
}
