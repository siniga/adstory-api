<?php

namespace App\Http\Controllers\Api\Adstory;

use App\Http\Controllers\Controller;
use App\Models\AdstoryEpisode;
use App\Models\AdstoryProject;
use App\Services\Adstory\AdstoryEpisodeLoaderService;
use App\Services\Adstory\AdstoryEpisodePlanningService;
use App\Services\Adstory\AdstoryEpisodeSceneGenerationService;
use App\Services\Adstory\AdstoryEpisodeShotGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdstoryEpisodeController extends Controller
{
    /** @var list<string> */
    private const LEGACY_RECOMMENDED_ENDPOINTS = [
        'GET /api/adstory/projects/{project}/sceneboard',
        'GET /api/adstory/projects/{project}/scenes/{scene}/sceneboard',
        'POST /api/adstory/projects/{project}/scenes/{scene}/generate-shots',
        'GET /api/adstory/projects/{project}/scenes/{scene}/shots/progress',
    ];

    public function __construct(
        private readonly AdstoryEpisodePlanningService $planningService,
        private readonly AdstoryEpisodeSceneGenerationService $sceneGenerationService,
        private readonly AdstoryEpisodeShotGenerationService $shotGenerationService,
        private readonly AdstoryEpisodeLoaderService $loaderService,
    ) {}

    public function plan(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'force' => 'sometimes|boolean',
            ]);

            $result = $this->planningService->plan(
                project: $project,
                force: (bool) ($validated['force'] ?? false),
            );

            $statusCode = ($result['started'] ?? false) ? 201 : 200;

            return $this->legacyResponse($result, $statusCode);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while planning episodes.');
        }
    }

    public function show(AdstoryProject $project, AdstoryEpisode $episode): JsonResponse
    {
        if ($episode->adstory_project_id !== $project->id) {
            return $this->notFoundResponse('Episode not found for this project.');
        }

        return $this->legacyResponse(
            $this->loaderService->show($project, $episode)
        );
    }

    public function generateScenes(Request $request, AdstoryProject $project, AdstoryEpisode $episode): JsonResponse
    {
        try {
            $validated = $request->validate([
                'force' => 'sometimes|boolean',
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->sceneGenerationService->startGeneration(
                project: $project,
                episode: $episode,
                force: (bool) ($validated['force'] ?? false),
                style: $validated['style'] ?? null,
            );

            $statusCode = ($result['started'] ?? false) ? 202 : 200;

            return $this->legacyResponse($result, $statusCode);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while starting episode scene generation.');
        }
    }

    public function sceneProgress(AdstoryProject $project, AdstoryEpisode $episode): JsonResponse
    {
        if ($episode->adstory_project_id !== $project->id) {
            return $this->notFoundResponse('Episode not found for this project.');
        }

        return $this->legacyResponse(
            $this->sceneGenerationService->buildSceneProgress($episode)
        );
    }

    public function storyboard(AdstoryProject $project, AdstoryEpisode $episode): JsonResponse
    {
        if ($episode->adstory_project_id !== $project->id) {
            return $this->notFoundResponse('Episode not found for this project.');
        }

        return $this->legacyResponse(
            $this->shotGenerationService->buildStoryboard($episode)
        );
    }

    public function generateShots(Request $request, AdstoryProject $project, AdstoryEpisode $episode): JsonResponse
    {
        try {
            $validated = $request->validate([
                'force' => 'sometimes|boolean',
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->shotGenerationService->startGeneration(
                project: $project,
                episode: $episode,
                force: (bool) ($validated['force'] ?? false),
                style: $validated['style'] ?? null,
            );

            $statusCode = ($result['started'] ?? false) ? 202 : 200;

            return $this->legacyResponse($result, $statusCode);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while starting episode shot generation.');
        }
    }

    public function shotProgress(AdstoryProject $project, AdstoryEpisode $episode): JsonResponse
    {
        if ($episode->adstory_project_id !== $project->id) {
            return $this->notFoundResponse('Episode not found for this project.');
        }

        return $this->legacyResponse(
            $this->shotGenerationService->buildShotProgress($episode)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function legacyResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json(array_merge($payload, [
            'deprecated' => true,
            'legacy' => true,
            'legacy_message' => 'Episode-based generation is deprecated. Use the Sceneboard flow instead.',
            'recommended_endpoints' => self::LEGACY_RECOMMENDED_ENDPOINTS,
        ]), $status);
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
