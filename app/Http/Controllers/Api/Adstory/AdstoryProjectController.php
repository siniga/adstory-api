<?php

namespace App\Http\Controllers\Api\Adstory;

use App\Http\Controllers\Controller;
use App\Models\AdstoryProject;
use App\Services\Adstory\AdstoryProjectDeletionService;
use App\Services\Adstory\AdstoryProjectFullLoaderService;
use App\Services\Adstory\AdstorySceneGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdstoryProjectController extends Controller
{
    public function __construct(
        private readonly AdstoryProjectDeletionService $projectDeletionService,
        private readonly AdstorySceneGenerationService $sceneGenerationService,
        private readonly AdstoryProjectFullLoaderService $fullLoaderService,
    ) {}

    public function index(): JsonResponse
    {
        $projects = AdstoryProject::query()
            ->select([
                'id',
                'title',
                'visual_style',
                'current_step',
                'status',
                'scene_generation_status',
                'scene_generation_total',
                'scene_generation_completed',
                'scene_generation_failed',
                'scene_generation_started_at',
                'scene_generation_finished_at',
                'shot_generation_status',
                'shot_generation_total',
                'shot_generation_completed',
                'shot_generation_failed',
                'shot_generation_started_at',
                'shot_generation_finished_at',
                'character_generation_status',
                'character_generation_total',
                'character_generation_completed',
                'character_generation_failed',
                'character_generation_started_at',
                'character_generation_finished_at',
                'created_at',
                'updated_at',
            ])
            ->withCount('episodes')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (AdstoryProject $project) => $project->toListApiArray())
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'projects' => $projects,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate($this->projectValidationRules());

            $project = AdstoryProject::query()->create([
                'title' => $validated['title'] ?? null,
                'story' => $validated['story'] ?? null,
                'script' => $validated['script'] ?? null,
                'screenplay' => $validated['screenplay'] ?? null,
                'visual_style' => $this->resolveVisualStyle($validated),
                'current_step' => $validated['current_step'] ?? 'story',
                'status' => $validated['status'] ?? 'draft',
                'meta' => $validated['meta'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'project' => $project->toApiArray(),
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while creating the project.');
        }
    }

    public function show(AdstoryProject $project): JsonResponse
    {
        return response()->json([
            'success' => true,
            'project' => $project->toApiArray(),
        ]);
    }

    public function full(Request $request, AdstoryProject $project): JsonResponse
    {
        $this->sceneGenerationService->syncSceneStatusesFromTasks($project);

        $includes = $this->fullLoaderService->parseIncludes($request->query('include'));

        return response()->json([
            'success' => true,
            'project' => $this->fullLoaderService->load($project, $includes),
        ]);
    }

    public function updateStory(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'story' => 'required_without:story_text|nullable|string|min:20',
                'story_text' => 'required_without:story|nullable|string|min:20',
                'title' => 'nullable|string|max:255',
                'visual_style' => 'nullable|string|max:255',
                'style' => 'nullable|string|max:255',
            ]);

            $project->story = $validated['story'] ?? $validated['story_text'];

            if (array_key_exists('title', $validated)) {
                $project->title = $validated['title'];
            }

            $visualStyle = $this->resolveVisualStyle($validated);
            if ($visualStyle !== null) {
                $project->visual_style = $visualStyle;
            }

            $project->current_step = 'story';
            $project->save();

            return $this->projectSuccessResponse($project);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while saving the story.');
        }
    }

    public function updateScript(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'script' => 'required|string',
            ]);

            $project->script = $validated['script'];
            $project->current_step = 'script';
            $project->save();

            return $this->projectSuccessResponse($project);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while saving the script.');
        }
    }

    public function updateScreenplay(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'screenplay' => 'required|string',
            ]);

            $project->screenplay = $validated['screenplay'];
            $project->current_step = 'screenplay';
            $project->save();

            return $this->projectSuccessResponse($project);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while saving the screenplay.');
        }
    }

    public function updateCore(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate($this->projectValidationRules());

            foreach (['title', 'story', 'script', 'screenplay', 'current_step', 'status', 'meta'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $project->{$field} = $validated[$field];
                }
            }

            $visualStyle = $this->resolveVisualStyle($validated);
            if ($visualStyle !== null) {
                $project->visual_style = $visualStyle;
            }

            $project->save();

            return $this->projectSuccessResponse($project);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while saving the project.');
        }
    }

    public function destroy(Request $request, int $project): JsonResponse
    {
        $projectModel = AdstoryProject::query()->find($project);

        if (! $projectModel) {
            return response()->json([
                'success' => false,
                'message' => 'Project not found.',
            ], 404);
        }

        if ($denied = $this->authorizeProjectDeletion($request, $projectModel)) {
            return $denied;
        }

        try {
            $result = $this->projectDeletionService->delete($projectModel);

            return response()->json($result);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while deleting the project.');
        }
    }

    /**
     * @return JsonResponse|null
     */
    private function authorizeProjectDeletion(Request $request, AdstoryProject $project): ?JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if (! Schema::hasColumn('adstory_projects', 'user_id')) {
            return null;
        }

        if ((int) $project->user_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to delete this project.',
            ], 403);
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function projectValidationRules(): array
    {
        return [
            'title' => 'nullable|string|max:255',
            'story' => 'nullable|string',
            'script' => 'nullable|string',
            'screenplay' => 'nullable|string',
            'visual_style' => 'nullable|string|max:255',
            'style' => 'nullable|string|max:255',
            'current_step' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:100',
            'meta' => 'nullable|array',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveVisualStyle(array $validated): ?string
    {
        if (array_key_exists('visual_style', $validated) && $validated['visual_style'] !== null) {
            return $validated['visual_style'];
        }

        if (array_key_exists('style', $validated) && $validated['style'] !== null) {
            return $validated['style'];
        }

        return null;
    }

    private function projectSuccessResponse(AdstoryProject $project): JsonResponse
    {
        return response()->json([
            'success' => true,
            'project' => $project->fresh()->toApiArray(),
        ]);
    }

    private function validationErrorResponse(ValidationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->validator->errors()->first(),
        ], 422);
    }

    private function unexpectedErrorResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 500);
    }
}
