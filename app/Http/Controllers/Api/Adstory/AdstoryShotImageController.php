<?php

namespace App\Http\Controllers\Api\Adstory;

use App\Http\Controllers\Controller;
use App\Models\AdstoryProject;
use App\Models\AdstoryShot;
use App\Models\AdstoryShotImage;
use App\Services\Adstory\AdstoryShotImageJobService;
use App\Services\Adstory\AdstoryShotImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdstoryShotImageController extends Controller
{
    public function __construct(
        private readonly AdstoryShotImageService $shotImageService,
        private readonly AdstoryShotImageJobService $shotImageJobService,
    ) {}

    public function index(AdstoryProject $project, AdstoryShot $shot): JsonResponse
    {
        if (! $this->shotImageService->shotBelongsToProject($shot, $project)) {
            return $this->notFoundResponse('Shot not found for this project.');
        }

        $images = $this->shotImageService->imagesForShot($shot);

        return response()->json([
            'success' => true,
            'images' => $images,
        ]);
    }

    public function generateForShot(Request $request, AdstoryProject $project, AdstoryShot $shot): JsonResponse
    {
        try {
            $validated = $request->validate([
                'prompt' => 'nullable|string',
                'custom_prompt' => 'nullable|string',
                'force' => 'sometimes|boolean',
            ]);

            if (! $this->shotImageService->shotBelongsToProject($shot, $project)) {
                return $this->notFoundResponse('Shot not found for this project.');
            }

            $customPrompt = $validated['prompt'] ?? $validated['custom_prompt'] ?? null;
            $force = (bool) ($validated['force'] ?? false);

            $queued = $this->shotImageJobService->queueShotImageJob($shot, $customPrompt, $force);

            return response()->json([
                'success' => true,
                'queued' => $queued,
                'shot' => $shot->fresh()->toApiArray(),
                'message' => $queued
                    ? 'Shot image generation queued.'
                    : 'Shot image generation already in progress or completed.',
            ], $queued ? 202 : 200);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while queueing the shot image.');
        }
    }

    public function generateBatch(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'shot_ids' => 'nullable|array',
                'shot_ids.*' => 'integer|exists:adstory_shots,id',
                'regenerate' => 'nullable|boolean',
            ]);

            $force = (bool) ($validated['regenerate'] ?? false);

            $query = $project->shots()->orderBy('order_index')->orderBy('id');

            if (! empty($validated['shot_ids'])) {
                $query->whereIn('id', $validated['shot_ids']);
            }

            $shots = $query->get();
            $queued = 0;
            $skipped = 0;

            Log::info('Adstory generate-shot-images batch: queueing jobs', [
                'project_id' => $project->id,
                'shot_count' => $shots->count(),
                'force' => $force,
            ]);

            foreach ($shots as $shot) {
                if ($this->shotImageJobService->queueShotImageJob($shot, force: $force)) {
                    $queued++;
                } else {
                    $skipped++;
                }
            }

            return response()->json([
                'success' => true,
                'queued' => $queued,
                'skipped' => $skipped,
                'generated' => 0,
                'failed' => 0,
                'message' => "Queued {$queued} shot image job(s).",
            ], $queued > 0 ? 202 : 200);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while queueing shot images.');
        }
    }

    public function approve(AdstoryProject $project, AdstoryShot $shot, AdstoryShotImage $image): JsonResponse
    {
        try {
            if (! $this->shotImageService->shotBelongsToProject($shot, $project)) {
                return $this->notFoundResponse('Shot not found for this project.');
            }

            if (! $this->shotImageService->imageBelongsToShotAndProject($image, $shot, $project)) {
                return $this->notFoundResponse('Image not found for this shot.');
            }

            $updatedShot = $this->shotImageService->approveImage($image);

            Log::info('Adstory approve-shot-image', [
                'project_id' => $project->id,
                'shot_id' => $shot->id,
                'image_id' => $image->id,
                'version_number' => $image->version_number,
            ]);

            return response()->json([
                'success' => true,
                'shot' => $updatedShot->toApiArray(),
                'image' => $image->fresh()->toApiArray(),
                'message' => 'Shot image approved successfully.',
            ]);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while approving the shot image.');
        }
    }

    public function destroy(AdstoryProject $project, AdstoryShot $shot, AdstoryShotImage $image): JsonResponse
    {
        if (! $this->shotImageService->shotBelongsToProject($shot, $project)) {
            return $this->notFoundResponse('Shot not found for this project.');
        }

        if (! $this->shotImageService->imageBelongsToShotAndProject($image, $shot, $project)) {
            return $this->notFoundResponse('Image not found for this shot.');
        }

        if ($image->storage_path) {
            Storage::disk('public')->delete($image->storage_path);
        }

        $wasApproved = $image->is_approved;
        $image->delete();

        if ($wasApproved) {
            $latest = $shot->shotImages()
                ->where('status', 'completed')
                ->orderByDesc('version_number')
                ->first();

            $shot->image_url = $latest?->image_url;
            $shot->image_status = $latest ? 'completed' : 'pending';
            $shot->prompt = $latest?->prompt;
            $shot->save();
        }

        Log::info('Adstory delete-shot-image', [
            'project_id' => $project->id,
            'shot_id' => $shot->id,
            'image_id' => $image->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shot image deleted successfully.',
        ]);
    }

    /**
     * Legacy endpoint: POST /api/adstory/generate-shot-image
     */
    public function generate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => 'required|integer|exists:adstory_projects,id',
                'shot_id' => 'required|integer|exists:adstory_shots,id',
                'prompt' => 'nullable|string',
                'custom_prompt' => 'nullable|string',
                'force' => 'sometimes|boolean',
            ]);

            $project = AdstoryProject::query()->findOrFail($validated['project_id']);
            $shot = AdstoryShot::query()->findOrFail($validated['shot_id']);

            if (! $this->shotImageService->shotBelongsToProject($shot, $project)) {
                return $this->notFoundResponse('Shot not found for this project.');
            }

            $customPrompt = $validated['prompt'] ?? $validated['custom_prompt'] ?? null;
            $force = (bool) ($validated['force'] ?? false);
            $queued = $this->shotImageJobService->queueShotImageJob($shot, $customPrompt, $force);

            return response()->json([
                'success' => true,
                'queued' => $queued,
                'shot' => $shot->fresh()->toApiArray(),
                'message' => $queued
                    ? 'Shot image generation queued.'
                    : 'Shot image generation already in progress or completed.',
            ], $queued ? 202 : 200);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while queueing the shot image.');
        }
    }

    /**
     * Legacy endpoint: PUT /api/adstory/shot-images/{image}/approve
     */
    public function approveLegacy(AdstoryShotImage $image): JsonResponse
    {
        try {
            $shot = $image->shot()->firstOrFail();
            $project = $shot->project()->firstOrFail();

            return $this->approve($project, $shot, $image);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while approving the shot image.');
        }
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
