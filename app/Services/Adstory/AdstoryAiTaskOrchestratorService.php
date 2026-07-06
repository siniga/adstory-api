<?php

namespace App\Services\Adstory;

use App\Models\AdstoryAiTask;
use App\Models\AdstoryCharacter;
use App\Models\AdstoryEnvironment;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdstoryAiTaskOrchestratorService
{
    public function __construct(
        private readonly AdstoryAiTaskService $aiTaskService,
        private readonly AdstoryAiTaskProgressService $progressService,
        private readonly AdstoryShotGenerationService $shotGenerationService,
        private readonly AdstoryCharacterGenerationService $characterGenerationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startShotGeneration(AdstoryProject $project, ?string $style = null): array
    {
        return $this->shotGenerationService->startGeneration($project, $style);
    }

    /**
     * @return array<string, mixed>
     */
    public function startCharacterExtraction(AdstoryProject $project, ?string $style = null): array
    {
        return $this->characterGenerationService->startGeneration($project, $style);
    }

    /**
     * @return array<string, mixed>
     */
    public function startCharacterImageGeneration(AdstoryProject $project, ?string $style = null): array
    {
        $this->assertNoActiveTasks($project, AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE);

        $characters = $project->characters()
            ->where(function ($query) {
                $query->whereNull('image_url')
                    ->orWhere('image_url', '')
                    ->orWhere('image_status', '!=', 'completed');
            })
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        if ($characters->isEmpty()) {
            throw new RuntimeException('No characters without images found for this project.');
        }

        $tasks = DB::transaction(function () use ($project, $characters, $style) {
            $this->aiTaskService->deleteTasksByType($project->id, AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE);

            $created = [];

            foreach ($characters as $index => $character) {
                $created[] = $this->aiTaskService->createTask(
                    project: $project,
                    type: AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE,
                    taskable: $character,
                    payload: ['style' => $style ?? $project->visual_style],
                    priority: 7000 - $index,
                );

                $character->update(['image_status' => 'queued']);
            }

            return $created;
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory AI task: character image generation started', [
            'project_id' => $project->id,
            'task_count' => count($tasks),
        ]);

        return [
            'success' => true,
            'project' => $project->fresh()->toApiArray(),
            'progress' => $this->progressService->buildTypeProgress(
                $project->fresh(),
                AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function startEnvironmentExtraction(AdstoryProject $project): array
    {
        $this->assertScreenplay($project);
        $this->assertNoActiveTasks($project, AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS);

        DB::transaction(function () use ($project) {
            $this->aiTaskService->deleteTasksByType($project->id, AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS);

            $this->aiTaskService->createTask(
                project: $project,
                type: AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS,
                taskable: $project,
                payload: [],
                priority: 8000,
            );
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory AI task: environment extraction started', [
            'project_id' => $project->id,
        ]);

        return [
            'success' => true,
            'project' => $project->fresh()->toApiArray(),
            'progress' => $this->progressService->buildTypeProgress(
                $project->fresh(),
                AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function startEnvironmentImageGeneration(AdstoryProject $project, ?string $style = null): array
    {
        $this->assertNoActiveTasks($project, AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE);

        $environments = $project->environments()
            ->where(function ($query) {
                $query->whereNull('image_url')
                    ->orWhere('image_url', '')
                    ->orWhere('image_status', '!=', 'completed');
            })
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        if ($environments->isEmpty()) {
            throw new RuntimeException('No environments without images found for this project.');
        }

        $tasks = DB::transaction(function () use ($project, $environments, $style) {
            $this->aiTaskService->deleteTasksByType($project->id, AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE);

            $created = [];

            foreach ($environments as $index => $environment) {
                $created[] = $this->aiTaskService->createTask(
                    project: $project,
                    type: AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE,
                    taskable: $environment,
                    payload: ['style' => $style ?? $project->visual_style],
                    priority: 7000 - $index,
                );

                $environment->update(['image_status' => 'queued']);
            }

            return $created;
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory AI task: environment image generation started', [
            'project_id' => $project->id,
            'task_count' => count($tasks),
        ]);

        return [
            'success' => true,
            'project' => $project->fresh()->toApiArray(),
            'progress' => $this->progressService->buildTypeProgress(
                $project->fresh(),
                AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE
            ),
        ];
    }

    private function assertScreenplay(AdstoryProject $project): void
    {
        if (mb_strlen(trim((string) ($project->screenplay ?? ''))) < 20) {
            throw new RuntimeException('Project must have a screenplay before starting extraction.');
        }
    }

    private function assertNoActiveTasks(AdstoryProject $project, string $type): void
    {
        $hasActive = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', $type)
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActive) {
            throw new RuntimeException("{$type} tasks are already running for this project.");
        }
    }
}
