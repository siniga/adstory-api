<?php

namespace App\Http\Controllers\Api\Adstory;

use App\Http\Controllers\Controller;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use App\Services\Adstory\AdstoryShotDirectorService;
use App\Services\Adstory\AdstoryShotGenerationService;
use App\Services\Adstory\AdstoryShotImageJobService;
use App\Services\Adstory\AdstoryShotService;
use App\Services\GeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use App\Support\ApiErrorResponder;

class AdstoryShotController extends Controller
{
    public function __construct(
        private readonly AdstoryShotService $shotService,
        private readonly AdstoryShotDirectorService $directorService,
        private readonly AdstoryShotGenerationService $shotGenerationService,
        private readonly AdstoryShotImageJobService $shotImageJobService,
        private readonly GeminiService $geminiService,
    ) {}

    public function index(AdstoryProject $project): JsonResponse
    {
        $shots = $project->shots()
            ->with('scene')
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $shotsArray = $shots
            ->map(fn (AdstoryShot $shot) => $shot->toApiArray())
            ->values()
            ->all();

        $groupedByScene = $shots
            ->groupBy('adstory_scene_id')
            ->map(function ($group, $sceneId) {
                $scene = $group->first()->scene;

                return [
                    'scene_id' => $sceneId !== '' ? (int) $sceneId : null,
                    'scene_number' => $scene?->scene_number,
                    'scene_title' => $scene?->title,
                    'shots' => $group
                        ->map(fn (AdstoryShot $shot) => $shot->toApiArray())
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'shots' => $shotsArray,
            'grouped_by_scene' => $groupedByScene,
        ]);
    }

    public function indexByScene(AdstoryProject $project, AdstoryScene $scene): JsonResponse
    {
        if (! $this->sceneBelongsToProject($scene, $project)) {
            return $this->notFoundResponse('Scene not found for this project.');
        }

        $shots = $scene->shots()
            ->orderBy('order_index')
            ->orderBy('id')
            ->get()
            ->map(fn (AdstoryShot $shot) => $shot->toApiArray())
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'shots' => $shots,
        ]);
    }

    public function startGeneration(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->shotGenerationService->startGeneration(
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
            return $this->unexpectedErrorResponse('An unexpected error occurred while starting shot generation.');
        }
    }

    public function resumeGeneration(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'retry_failed' => 'sometimes|boolean',
                'style' => 'nullable|string|max:255',
            ]);

            $result = $this->shotGenerationService->resumeGeneration(
                project: $project,
                retryFailed: (bool) ($validated['retry_failed'] ?? false),
                style: $validated['style'] ?? null,
            );

            $statusCode = ($result['tasks_created'] ?? 0) > 0 ? 202 : 200;

            return response()->json($result, $statusCode);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while resuming shot generation.');
        }
    }

    public function progress(AdstoryProject $project): JsonResponse
    {
        return response()->json(array_merge(
            $this->shotGenerationService->getProgress($project),
            [
                'deprecated' => true,
                'legacy' => true,
                'message' => 'Project-wide shot progress is deprecated. Use per-scene shot endpoints from the Storyboard/Shots step.',
                'recommended_endpoints' => [
                    'GET /api/adstory/projects/{project}/shots',
                    'GET /api/adstory/projects/{project}/scenes/{scene}/shots',
                    'POST /api/adstory/projects/{project}/scenes/{scene}/generate-shots',
                    'GET /api/adstory/projects/{project}/scenes/{scene}/shots/progress',
                ],
            ]
        ));
    }

    public function store(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate($this->shotItemRules());
            $scenes = $project->scenes()->get();

            $orderIndex = $validated['order_index']
                ?? (($project->shots()->max('order_index') ?? -1) + 1);

            $sceneId = $this->shotService->resolveSceneId($scenes, $validated);

            $shot = AdstoryShot::query()->create(
                $this->shotService->mapShotAttributes(
                    project: $project,
                    scenes: $scenes,
                    data: array_merge($validated, ['order_index' => $orderIndex]),
                    orderIndex: $orderIndex,
                    sceneIdOverride: $sceneId,
                )
            );

            return response()->json([
                'success' => true,
                'shot' => $shot->load('scene')->toApiArray(),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while creating the shot.');
        }
    }

    public function update(Request $request, AdstoryProject $project, AdstoryShot $shot): JsonResponse
    {
        if (! $this->shotBelongsToProject($shot, $project)) {
            return $this->notFoundResponse('Shot not found for this project.');
        }

        try {
            $validated = $request->validate($this->shotItemRules(required: false));
            $scenes = $project->scenes()->get();

            $attributes = $this->shotService->mapShotAttributes(
                project: $project,
                scenes: $scenes,
                data: array_merge($shot->toApiArray(), $validated),
                orderIndex: $validated['order_index'] ?? $shot->order_index,
            );

            unset($attributes['adstory_project_id']);
            $shot->fill($attributes);
            $shot->save();

            return response()->json([
                'success' => true,
                'shot' => $shot->fresh(['scene'])->toApiArray(),
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while updating the shot.');
        }
    }

    public function destroy(AdstoryProject $project, AdstoryShot $shot): JsonResponse
    {
        if (! $this->shotBelongsToProject($shot, $project)) {
            return $this->notFoundResponse('Shot not found for this project.');
        }

        $shot->delete();

        return response()->json([
            'success' => true,
            'message' => 'Shot deleted successfully.',
        ]);
    }

    public function bulkReplace(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'shots' => 'required|array',
                ...$this->shotArrayRules(),
            ]);

            $shots = $this->shotService->replaceProjectShots(
                project: $project,
                shotsData: $validated['shots'],
            );

            return response()->json([
                'success' => true,
                'shots' => $shots,
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while saving shots.');
        }
    }

    public function director(Request $request, AdstoryProject $project, AdstoryShot $shot): JsonResponse
    {
        if (! $this->shotBelongsToProject($shot, $project)) {
            return $this->notFoundResponse('Shot not found for this project.');
        }

        try {
            $validated = $request->validate([
                'instruction' => 'required|string|min:3|max:2000',
            ]);

            $project->load(['characters', 'environments']);
            $shot->load('scene');

            $characterAssets = $this->directorService->resolveSelectedCharacterAssets($shot, $project);
            $environmentAssets = $this->directorService->resolveSelectedEnvironmentAssets($shot, $project);

            $prompt = $this->directorService->buildDirectorPrompt(
                project: $project,
                shot: $shot,
                scene: $shot->scene,
                characterAssets: $characterAssets,
                environmentAssets: $environmentAssets,
                instruction: $validated['instruction'],
            );

            Log::info('Adstory shot-director: starting', [
                'project_id' => $project->id,
                'shot_id' => $shot->id,
                'character_asset_ids' => array_map(fn ($a) => $a->id, $characterAssets),
                'environment_asset_ids' => array_map(fn ($a) => $a->id, $environmentAssets),
            ]);

            $responseText = $this->geminiService->generateText($prompt);
            $director = $this->directorService->parseDirectorJson($responseText);

            Log::info('Adstory shot-director: success', [
                'project_id' => $project->id,
                'shot_id' => $shot->id,
            ]);

            return response()->json([
                'success' => true,
                'director' => $director,
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
            Log::error('Adstory shot-director: failed', [
                'project_id' => $project->id,
                'shot_id' => $shot->id,
                'message' => $e->getMessage(),
            ]);

            return $this->unexpectedErrorResponse('An unexpected error occurred while generating director suggestions.');
        }
    }

    public function updateStoryboardSettings(Request $request, AdstoryProject $project, AdstoryShot $shot): JsonResponse
    {
        if (! $this->shotBelongsToProject($shot, $project)) {
            return $this->notFoundResponse('Shot not found for this project.');
        }

        try {
            $validated = $request->validate([
                'selected_character_assets' => 'nullable|array',
                'selected_environment_assets' => 'nullable|array',
                'composition_preset' => 'nullable|array',
                'cinematography_preset' => 'nullable|array',
                'lighting_preset' => 'nullable|array',
                'storyboard_settings' => 'nullable|array',
            ]);

            $shot->fill($validated);
            $shot->save();

            return response()->json([
                'success' => true,
                'shot' => $shot->fresh(['scene', 'shotImages', 'approvedImage'])->toApiArray(),
            ]);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while updating storyboard settings.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function shotItemRules(bool $required = false): array
    {
        return [
            'adstory_scene_id' => 'nullable|integer',
            'scene_id' => 'nullable|integer',
            'scene_number' => 'nullable|integer',
            'scene_title' => 'nullable|string|max:255',
            'shot_number' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'action' => 'nullable|string',
            'dialogue' => 'nullable|string',
            'shot_size' => 'nullable|string|max:255',
            'camera_angle' => 'nullable|string|max:255',
            'camera_movement' => 'nullable|string|max:255',
            'composition' => 'nullable|string|max:255',
            'lens' => 'nullable|string|max:255',
            'lighting' => 'nullable|string|max:255',
            'environment' => 'nullable|string|max:255',
            'characters' => 'nullable|array',
            'duration_seconds' => 'nullable|integer',
            'prompt' => 'nullable|string',
            'image_url' => 'nullable|string',
            'image_status' => 'nullable|string|max:100',
            'order_index' => 'nullable|integer',
            'status' => 'nullable|string|max:100',
            'meta' => 'nullable|array',
            'mood' => 'nullable|string|max:255',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function shotArrayRules(): array
    {
        return [
            'shots.*.adstory_scene_id' => 'nullable|integer',
            'shots.*.scene_id' => 'nullable|integer',
            'shots.*.scene_number' => 'nullable|integer',
            'shots.*.scene_title' => 'nullable|string|max:255',
            'shots.*.shot_number' => 'nullable|string|max:255',
            'shots.*.title' => 'nullable|string|max:255',
            'shots.*.description' => 'nullable|string',
            'shots.*.action' => 'nullable|string',
            'shots.*.dialogue' => 'nullable|string',
            'shots.*.shot_size' => 'nullable|string|max:255',
            'shots.*.camera_angle' => 'nullable|string|max:255',
            'shots.*.camera_movement' => 'nullable|string|max:255',
            'shots.*.composition' => 'nullable|string|max:255',
            'shots.*.lens' => 'nullable|string|max:255',
            'shots.*.lighting' => 'nullable|string|max:255',
            'shots.*.environment' => 'nullable|string|max:255',
            'shots.*.characters' => 'nullable|array',
            'shots.*.duration_seconds' => 'nullable|integer',
            'shots.*.prompt' => 'nullable|string',
            'shots.*.image_url' => 'nullable|string',
            'shots.*.image_status' => 'nullable|string|max:100',
            'shots.*.order_index' => 'nullable|integer',
            'shots.*.status' => 'nullable|string|max:100',
            'shots.*.meta' => 'nullable|array',
            'shots.*.mood' => 'nullable|string|max:255',
        ];
    }

    public function retryImageGeneration(AdstoryProject $project, AdstoryShot $shot): JsonResponse
    {
        try {
            if (! $this->shotBelongsToProject($shot, $project)) {
                return $this->notFoundResponse('Shot not found for this project.');
            }

            $this->shotImageJobService->retryShot($project, $shot);

            return response()->json([
                'success' => true,
                'queued' => true,
                'shot' => $shot->fresh()->toApiArray(),
                'message' => 'Shot image generation retry queued.',
            ], 202);
        } catch (RuntimeException $e) {
            return $this->notFoundResponse($e->getMessage());
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while retrying shot image generation.');
        }
    }

    private function sceneBelongsToProject(AdstoryScene $scene, AdstoryProject $project): bool
    {
        return $scene->adstory_project_id === $project->id;
    }

    private function shotBelongsToProject(AdstoryShot $shot, AdstoryProject $project): bool
    {
        return $shot->adstory_project_id === $project->id;
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
