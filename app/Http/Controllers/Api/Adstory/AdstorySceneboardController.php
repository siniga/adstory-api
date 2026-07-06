<?php

namespace App\Http\Controllers\Api\Adstory;

use App\Http\Controllers\Controller;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Services\Adstory\AdstorySceneboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdstorySceneboardController extends Controller
{
    public function __construct(
        private readonly AdstorySceneboardService $sceneboardService,
    ) {}

    public function index(AdstoryProject $project): JsonResponse
    {
        return response()->json(
            $this->sceneboardService->loadProjectSceneboard($project)
        );
    }

    public function show(AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        try {
            return response()->json(
                $this->sceneboardService->loadSceneSceneboard($project, $scene)
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

            $result = $this->sceneboardService->startSceneShotGeneration(
                project: $project,
                scene: $scene,
                force: (bool) ($validated['force'] ?? false),
                style: $validated['style'] ?? null,
            );

            $statusCode = ($result['started'] ?? false) ? 202 : 200;

            return response()->json(
                array_merge($result, $this->legacySceneboardShotMeta()),
                $statusCode
            );
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
            return response()->json(array_merge(
                $this->sceneboardService->buildSceneShotProgress($project, $scene),
                $this->legacySceneboardShotMeta(),
            ));
        } catch (RuntimeException $e) {
            return $this->notFoundResponse($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function legacySceneboardShotMeta(): array
    {
        return [
            'deprecated' => true,
            'legacy' => true,
            'legacy_message' => 'Per-scene shot generation from the Sceneboard is deprecated. Complete Characters and Environments first, then generate shots from the Storyboard/Shots step.',
            'recommended_endpoints' => [
                'POST /api/adstory/projects/{project}/characters/start-generation',
                'POST /api/adstory/projects/{project}/environments/start-generation',
                'GET /api/adstory/projects/{project}/shots',
                'GET /api/adstory/projects/{project}/scenes/{scene}/shots',
            ],
        ];
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
