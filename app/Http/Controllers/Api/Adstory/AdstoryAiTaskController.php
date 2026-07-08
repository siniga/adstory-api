<?php

namespace App\Http\Controllers\Api\Adstory;

use App\Http\Controllers\Controller;
use App\Models\AdstoryAiTask;
use App\Models\AdstoryProject;
use App\Services\Adstory\AdstoryAiTaskProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use App\Support\ApiErrorResponder;

class AdstoryAiTaskController extends Controller
{
    public function __construct(
        private readonly AdstoryAiTaskProgressService $progressService,
    ) {}

    public function summary(AdstoryProject $project): JsonResponse
    {
        return response()->json([
            'success' => true,
            'project_id' => $project->id,
            'summary' => $this->progressService->getProjectSummary($project),
            'by_type' => $this->progressService->getCountsByTypeAndStatus($project->id),
        ]);
    }

    public function progress(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'type' => 'nullable|string|in:'.implode(',', AdstoryAiTaskProgressService::ALL_TASK_TYPES),
            ]);

            $type = $validated['type'] ?? null;

            if ($type !== null) {
                return response()->json($this->progressService->buildTypeProgress($project, $type));
            }

            return response()->json($this->progressService->getProgress($project));
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while fetching AI task progress.');
        }
    }

    public function retry(Request $request, AdstoryProject $project): JsonResponse
    {
        try {
            $validated = $request->validate([
                'type' => 'required|string|in:'.implode(',', AdstoryAiTaskProgressService::ALL_TASK_TYPES),
                'retry_failed' => 'nullable|boolean',
                'retry_stalled' => 'nullable|boolean',
            ]);

            $progress = $this->progressService->retry(
                project: $project,
                type: $validated['type'],
                retryFailed: (bool) ($validated['retry_failed'] ?? true),
                retryStalled: (bool) ($validated['retry_stalled'] ?? true),
            );

            return response()->json($progress);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return $this->unexpectedErrorResponse('An unexpected error occurred while retrying AI tasks.');
        }
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

    private function unexpectedErrorResponse(string $message): JsonResponse
    {
        return ApiErrorResponder::error($message, 500, 'unexpected_error');
    }
}
