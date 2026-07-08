<?php

namespace App\Http\Controllers\Api\Adstory;

use App\Http\Controllers\Controller;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Services\Adstory\AdstoryStoryboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use App\Support\ApiErrorResponder;

class AdstoryStoryboardController extends Controller
{
    public function __construct(
        private readonly AdstoryStoryboardService $storyboardService,
    ) {}

    public function index(AdstoryProject $project): JsonResponse
    {
        return response()->json(
            $this->storyboardService->loadProjectStoryboard($project)
        );
    }

    public function show(AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        try {
            return response()->json(
                $this->storyboardService->loadSceneStoryboard($project, $scene)
            );
        } catch (RuntimeException $e) {
            return $this->notFoundResponse($e->getMessage());
        }
    }

    public function generateShots(Request $request, AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        try {
            $validated = $request->validate([
                'force' => 'sometimes|boolean',
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->storyboardService->startSceneShotGeneration(
                project: $project,
                scene: $scene,
                force: (bool) ($validated['force'] ?? false),
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
            return $this->unexpectedErrorResponse('An unexpected error occurred while starting shot generation.');
        }
    }

    public function shotProgress(AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        try {
            return response()->json(
                $this->storyboardService->buildSceneShotProgress($project, $scene)
            );
        } catch (RuntimeException $e) {
            return $this->notFoundResponse($e->getMessage());
        }
    }

    public function generateAllShotImages(Request $request, AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        try {
            $validated = $request->validate([
                'force' => 'sometimes|boolean',
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->storyboardService->startSceneShotImageGeneration(
                project: $project,
                scene: $scene,
                force: (bool) ($validated['force'] ?? false),
                style: $validated['style'] ?? null,
            );

            $statusCode = ($result['started'] ?? false) ? 202 : 200;

            return response()->json($result, $statusCode);
        } catch (RuntimeException $e) {
            $statusCode = str_contains($e->getMessage(), 'no shots yet') ? 422 : 404;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $statusCode);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while starting shot image generation.');
        }
    }

    public function shotImageProgress(AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        try {
            return response()->json(
                $this->storyboardService->buildSceneShotImageProgress($project, $scene)
            );
        } catch (RuntimeException $e) {
            return $this->notFoundResponse($e->getMessage());
        }
    }

    public function resumeShotImages(Request $request, AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        try {
            $validated = $request->validate([
                'retry_failed' => 'sometimes|boolean',
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->storyboardService->resumeSceneShotImageGeneration(
                project: $project,
                scene: $scene,
                retryFailed: (bool) ($validated['retry_failed'] ?? false),
                style: $validated['style'] ?? null,
            );

            $statusCode = ($result['tasks_created'] ?? 0) > 0 ? 202 : 200;

            return response()->json($result, $statusCode);
        } catch (RuntimeException $e) {
            return $this->notFoundResponse($e->getMessage());
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while resuming shot image generation.');
        }
    }

    public function cancelShots(AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        try {
            return response()->json(
                $this->storyboardService->cancelSceneShotGeneration($project, $scene)
            );
        } catch (RuntimeException $e) {
            return $this->notFoundResponse($e->getMessage());
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while cancelling shot generation.');
        }
    }

    public function cancelShotImages(AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        try {
            return response()->json(
                $this->storyboardService->cancelSceneShotImageGeneration($project, $scene)
            );
        } catch (RuntimeException $e) {
            return $this->notFoundResponse($e->getMessage());
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while cancelling shot image generation.');
        }
    }

    public function generationProgress(AdstoryProject $project): JsonResponse
    {
        return response()->json(
            $this->storyboardService->buildProjectGenerationProgress($project)
        );
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
