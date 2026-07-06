<?php

namespace App\Services\Adstory;

use App\Models\AdstoryAiTask;
use App\Models\AdstoryProject;
use Illuminate\Support\Facades\Log;

class AdstoryAiTaskProgressService
{
    /** @var list<string> */
    public const ALL_TASK_TYPES = [
        AdstoryAiTask::TYPE_GENERATE_SCENE,
        AdstoryAiTask::TYPE_GENERATE_EPISODE_SCENES,
        AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE,
        AdstoryAiTask::TYPE_EXTRACT_CHARACTERS,
        AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE,
        AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS,
        AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE,
    ];

    /** @var array<string, list<string>> */
    public const SUMMARY_GROUPS = [
        'scenes' => [AdstoryAiTask::TYPE_GENERATE_SCENE, AdstoryAiTask::TYPE_GENERATE_EPISODE_SCENES],
        'shots' => [AdstoryAiTask::TYPE_GENERATE_SHOTS_FOR_SCENE],
        'characters' => [
            AdstoryAiTask::TYPE_EXTRACT_CHARACTERS,
            AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE,
        ],
        'environments' => [
            AdstoryAiTask::TYPE_EXTRACT_ENVIRONMENTS,
            AdstoryAiTask::TYPE_GENERATE_ENVIRONMENT_IMAGE,
        ],
    ];

    public function __construct(
        private readonly AdstoryAiTaskService $aiTaskService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getProgress(AdstoryProject $project, ?string $type = null): array
    {
        if ($type !== null) {
            return $this->buildTypeProgress($project, $type);
        }

        return [
            'success' => true,
            'project_id' => $project->id,
            'summary' => $this->getProjectSummary($project),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getProjectSummary(AdstoryProject $project): array
    {
        $summary = [];

        foreach (self::SUMMARY_GROUPS as $group => $types) {
            $summary[$group] = $this->buildGroupSummary($project, $types);
        }

        return $summary;
    }

    /**
     * Lightweight counts grouped by task type and status — no payload/result columns loaded.
     *
     * @return array<string, array<string, int>>
     */
    public function getCountsByTypeAndStatus(int $projectId): array
    {
        $rows = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->selectRaw('type, status, COUNT(*) as aggregate_count')
            ->groupBy('type', 'status')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $type = (string) $row->type;
            $status = (string) $row->status;

            if (! isset($counts[$type])) {
                $counts[$type] = [
                    'queued' => 0,
                    'running' => 0,
                    'completed' => 0,
                    'failed' => 0,
                    'cancelled' => 0,
                ];
            }

            if (array_key_exists($status, $counts[$type])) {
                $counts[$type][$status] = (int) $row->aggregate_count;
            }
        }

        return $counts;
    }

    /**
     * @param  list<string>  $types
     * @return array<string, mixed>
     */
    public function buildGroupSummary(AdstoryProject $project, array $types): array
    {
        $counts = $this->aggregateCounts($project->id, $types);
        $total = array_sum($counts);
        $completed = $counts['completed'];
        $progressPercent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        return [
            'total' => $total,
            'queued' => $counts['queued'],
            'running' => $counts['running'],
            'completed' => $counts['completed'],
            'failed' => $counts['failed'],
            'cancelled' => $counts['cancelled'],
            'progress_percent' => $progressPercent,
            'has_active' => ($counts['queued'] + $counts['running']) > 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTypeProgress(AdstoryProject $project, string $type): array
    {
        if ($type === AdstoryAiTask::TYPE_GENERATE_SCENE) {
            app(AdstorySceneGenerationService::class)->syncSceneStatusesFromTasks($project);
        }

        $this->aiTaskService->resetStaleRunningTasks($project->id, $type);

        $counts = $this->aiTaskService->getTaskCounts($project->id, $type);
        $total = array_sum($counts);
        $completed = $counts['completed'];
        $progressPercent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        $currentTask = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', $type)
            ->where('status', AdstoryAiTask::STATUS_RUNNING)
            ->orderByDesc('id')
            ->first();

        if (! $currentTask) {
            $currentTask = AdstoryAiTask::query()
                ->where('adstory_project_id', $project->id)
                ->where('type', $type)
                ->where('status', AdstoryAiTask::STATUS_QUEUED)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->first();
        }

        $failedTasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', $type)
            ->where('status', AdstoryAiTask::STATUS_FAILED)
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (AdstoryAiTask $task) => $task->toSummaryArray())
            ->values()
            ->all();

        $recentTasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', $type)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (AdstoryAiTask $task) => $task->toSummaryArray())
            ->values()
            ->all();

        Log::info('Adstory AI task: progress calculated', [
            'project_id' => $project->id,
            'type' => $type,
            'total' => $total,
            'completed' => $completed,
            'failed' => $counts['failed'],
        ]);

        return [
            'success' => true,
            'project_id' => $project->id,
            'type' => $type,
            'total' => $total,
            'queued' => $counts['queued'],
            'running' => $counts['running'],
            'completed' => $counts['completed'],
            'failed' => $counts['failed'],
            'cancelled' => $counts['cancelled'],
            'progress_percent' => $progressPercent,
            'current_task' => $currentTask?->toSummaryArray(),
            'failed_tasks' => $failedTasks,
            'tasks' => $recentTasks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retry(
        AdstoryProject $project,
        string $type,
        bool $retryFailed = true,
        bool $retryStalled = true,
    ): array {
        if ($retryStalled) {
            $this->aiTaskService->resetStaleRunningTasks($project->id, $type);
        }

        if ($retryFailed) {
            $this->aiTaskService->resetFailedTasks($project->id, $type);
        }

        if ($this->aiTaskService->hasEligibleQueuedTasks($project->id, $type)) {
            $this->aiTaskService->dispatchWorker();

            Log::info('Adstory AI task: retried and worker dispatched', [
                'project_id' => $project->id,
                'type' => $type,
                'retry_failed' => $retryFailed,
                'retry_stalled' => $retryStalled,
            ]);
        }

        return $this->buildTypeProgress($project, $type);
    }

    /**
     * @param  list<string>  $types
     * @return array<string, int>
     */
    private function aggregateCounts(int $projectId, array $types): array
    {
        $totals = [
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];

        foreach ($types as $type) {
            $counts = $this->aiTaskService->getTaskCounts($projectId, $type);

            foreach ($totals as $key => $value) {
                $totals[$key] += $counts[$key];
            }
        }

        return $totals;
    }
}
