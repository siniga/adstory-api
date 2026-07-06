<?php

namespace App\Services\Adstory;

use App\Models\AdstoryProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AdstoryProjectDeletionService
{
    /**
     * @var list<string>
     */
    private const PROJECT_STORAGE_PATHS = [
        'adstory/projects/%d',
        'screenly/projects/%d',
    ];

    public function __construct(
        private readonly AdstoryAiTaskService $aiTaskService,
    ) {}

    /**
     * @return array{success: bool, message: string}
     */
    public function delete(AdstoryProject $project): array
    {
        $projectId = $project->id;
        $projectTitle = $project->title;

        Log::info('Adstory project: deletion started', [
            'project_id' => $projectId,
            'title' => $projectTitle,
        ]);

        try {
            DB::transaction(function () use ($project) {
                $this->aiTaskService->cancelAllActiveTasks($project->id);
                $project->delete();
            });

            $this->deleteProjectStorage($projectId);

            Log::info('Adstory project: deletion completed', [
                'project_id' => $projectId,
                'title' => $projectTitle,
            ]);

            return [
                'success' => true,
                'message' => 'Project deleted successfully.',
            ];
        } catch (Throwable $e) {
            Log::error('Adstory project: deletion failed', [
                'project_id' => $projectId,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function deleteProjectStorage(int $projectId): void
    {
        $disk = Storage::disk('public');

        foreach (self::PROJECT_STORAGE_PATHS as $pathTemplate) {
            $path = sprintf($pathTemplate, $projectId);

            if (! $disk->exists($path)) {
                continue;
            }

            $disk->deleteDirectory($path);

            Log::info('Adstory project: storage folder deleted', [
                'project_id' => $projectId,
                'path' => $path,
            ]);
        }
    }
}
