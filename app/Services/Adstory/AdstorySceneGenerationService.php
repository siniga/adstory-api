<?php

namespace App\Services\Adstory;

use App\Models\AdstoryAiTask;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Services\GeminiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class AdstorySceneGenerationService
{
    public const SCENE_STATUS_PENDING = 'pending';

    public const SCENE_STATUS_QUEUED = 'queued';

    public const SCENE_STATUS_GENERATING = 'generating';

    public const SCENE_STATUS_COMPLETED = 'completed';

    public const SCENE_STATUS_FAILED = 'failed';

    public const PROJECT_STATUS_RUNNING = 'running';

    public const PROJECT_STATUS_PAUSED = 'paused';

    public const PROJECT_STATUS_IDLE = 'idle';

    public const PROJECT_STATUS_COMPLETED = 'completed';

    public const PROJECT_STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const PROJECT_STATUS_CANCELLED = 'cancelled';

    public const PROJECT_STATUS_RESTARTING = 'restarting';

    /** @var list<string> */
    public const BLOCKED_WORKER_STATUSES = [
        self::PROJECT_STATUS_PAUSED,
        self::PROJECT_STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly GeminiService $geminiService,
        private readonly AdstorySceneService $sceneService,
        private readonly AdstoryAiTaskService $aiTaskService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startGeneration(AdstoryProject $project, ?string $visualStyle = null): array
    {
        $screenplay = trim((string) ($project->screenplay ?? ''));

        if (mb_strlen($screenplay) < 20) {
            throw new RuntimeException('Project must have a screenplay before starting scene generation.');
        }

        if (in_array($project->scene_generation_status, [self::PROJECT_STATUS_RUNNING, self::PROJECT_STATUS_RESTARTING], true)) {
            throw new RuntimeException('Scene generation is already running for this project.');
        }

        return $this->bootstrapSceneGeneration($project, $visualStyle);
    }

    /**
     * @return array<string, mixed>
     */
    private function bootstrapSceneGeneration(AdstoryProject $project, ?string $visualStyle = null): array
    {
        $screenplay = trim((string) ($project->screenplay ?? ''));

        if (mb_strlen($screenplay) < 20) {
            throw new RuntimeException('Project must have a screenplay before starting scene generation.');
        }

        $style = $visualStyle ?? $project->visual_style;

        Log::info('Adstory scene-generation: planning started', [
            'project_id' => $project->id,
        ]);

        $planPrompt = $this->buildScenePlanPrompt($screenplay, $style);
        $planResponse = $this->geminiService->generateText($planPrompt);
        $planItems = $this->parseScenePlanJson($planResponse);

        $tasks = DB::transaction(function () use ($project, $planItems, $style) {
            $this->aiTaskService->deleteSceneTasks($project->id);
            $project->scenes()->delete();

            $createdTasks = [];

            foreach ($planItems as $index => $planItem) {
                $title = (string) ($planItem['title'] ?? 'Untitled scene');
                $summary = (string) ($planItem['summary'] ?? '');

                $scene = AdstoryScene::query()->create(
                    $this->sceneService->mapSceneAttributes(
                        projectId: $project->id,
                        data: [
                            'scene_number' => $planItem['scene_number'] ?? ($index + 1),
                            'title' => $title,
                            'slug' => Str::slug($title),
                            'location' => $planItem['location'] ?? null,
                            'time_of_day' => $planItem['time_of_day'] ?? null,
                            'description' => $summary !== '' ? $summary : null,
                            'status' => self::SCENE_STATUS_QUEUED,
                            'meta' => [
                                'summary' => $summary,
                                'purpose' => $summary,
                                'generation_plan' => $planItem,
                            ],
                        ],
                        orderIndex: $index,
                        visualStyle: $style,
                    )
                );

                $createdTasks[] = $this->aiTaskService->createGenerateSceneTask(
                    project: $project,
                    scene: $scene,
                    planItem: $planItem,
                    orderIndex: $index,
                );
            }

            $project->update([
                'scene_generation_status' => self::PROJECT_STATUS_RUNNING,
                'scene_generation_total' => count($createdTasks),
                'scene_generation_completed' => 0,
                'scene_generation_failed' => 0,
                'scene_generation_started_at' => now(),
                'scene_generation_finished_at' => null,
                'current_step' => 'scenes',
            ]);

            return $createdTasks;
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory scene-generation: tasks created and worker dispatched', [
            'project_id' => $project->id,
            'task_count' => count($tasks),
        ]);

        $project->refresh()->load('scenes');

        return [
            'project' => $project->toApiArray(),
            'scenes' => $project->scenes
                ->map(fn (AdstoryScene $scene) => $scene->toApiArray())
                ->values()
                ->all(),
            'tasks' => $this->buildTaskSummary($project),
            'progress' => $this->buildProgressPayload($project),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pauseGeneration(AdstoryProject $project): array
    {
        if ($project->scene_generation_status !== self::PROJECT_STATUS_RUNNING) {
            throw new RuntimeException('Only a running generation can be paused.');
        }

        $project->update([
            'scene_generation_status' => self::PROJECT_STATUS_PAUSED,
        ]);

        Log::info('Adstory scene-generation: paused', [
            'project_id' => $project->id,
        ]);

        return $this->buildProgressPayload($project->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelGeneration(AdstoryProject $project): array
    {
        if (! in_array($project->scene_generation_status, [
            self::PROJECT_STATUS_RUNNING,
            self::PROJECT_STATUS_PAUSED,
            self::PROJECT_STATUS_RESTARTING,
        ], true)) {
            throw new RuntimeException('No active scene generation to cancel.');
        }

        $cancelledCount = $this->aiTaskService->cancelQueuedSceneTasks($project->id);

        $project->update([
            'scene_generation_status' => self::PROJECT_STATUS_CANCELLED,
            'scene_generation_finished_at' => now(),
        ]);

        Log::info('Adstory scene-generation: cancelled', [
            'project_id' => $project->id,
            'cancelled_tasks' => $cancelledCount,
        ]);

        return $this->buildProgressPayload($project->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function restartGeneration(AdstoryProject $project, bool $deleteExisting = false, ?string $visualStyle = null): array
    {
        if (in_array($project->scene_generation_status, [self::PROJECT_STATUS_RUNNING, self::PROJECT_STATUS_RESTARTING], true)) {
            throw new RuntimeException('Scene generation is already running for this project.');
        }

        $screenplay = trim((string) ($project->screenplay ?? ''));

        if (mb_strlen($screenplay) < 20) {
            throw new RuntimeException('Project must have a screenplay before restarting scene generation.');
        }

        $project->update(['scene_generation_status' => self::PROJECT_STATUS_RESTARTING]);

        if ($deleteExisting) {
            Log::info('Adstory scene-generation: restarting from scratch', [
                'project_id' => $project->id,
            ]);

            return $this->bootstrapSceneGeneration($project->fresh(), $visualStyle);
        }

        Log::info('Adstory scene-generation: restarting unfinished only', [
            'project_id' => $project->id,
        ]);

        $this->aiTaskService->resetStaleRunningTasks($project->id);
        $this->aiTaskService->resetIncompleteSceneTasks($project->id);

        AdstoryScene::query()
            ->where('adstory_project_id', $project->id)
            ->where('status', '!=', self::SCENE_STATUS_COMPLETED)
            ->update([
                'status' => self::SCENE_STATUS_QUEUED,
                'generation_error' => null,
                'generated_at' => null,
            ]);

        $project->update([
            'scene_generation_status' => self::PROJECT_STATUS_RUNNING,
            'scene_generation_finished_at' => null,
        ]);

        $project->load(['scenes' => fn ($query) => $query->orderBy('order_index')->orderBy('id')]);

        foreach ($project->scenes->where('status', self::SCENE_STATUS_QUEUED) as $scene) {
            $this->aiTaskService->resetOrCreateSceneTask($project, $scene);
        }

        $this->aiTaskService->dispatchWorker();

        return $this->buildProgressPayload($project->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function getProgress(AdstoryProject $project): array
    {
        return $this->buildProgressPayload($project);
    }

    /**
     * Reconcile every scene row with its generate_scene AI task outcome.
     * Safe to call repeatedly — does not regenerate or delete scenes.
     */
    public function syncSceneStatusesFromTasks(AdstoryProject $project): int
    {
        $repaired = 0;

        $scenes = AdstoryScene::query()
            ->where('adstory_project_id', $project->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        foreach ($scenes as $scene) {
            $task = $this->findGenerateSceneTaskForScene($project, $scene);

            if (! $task) {
                continue;
            }

            if ($task->status === AdstoryAiTask::STATUS_COMPLETED && $scene->status !== self::SCENE_STATUS_COMPLETED) {
                $this->sceneService->markSceneCompleted($scene, $task->completed_at);
                $repaired++;

                continue;
            }

            if ($task->status === AdstoryAiTask::STATUS_FAILED && $scene->status !== self::SCENE_STATUS_FAILED) {
                $this->sceneService->markSceneFailed(
                    $scene,
                    (string) ($task->error ?? 'Scene generation failed.')
                );
                $repaired++;
            }
        }

        if ($repaired > 0) {
            Log::info('Adstory scene: task status sync finished', [
                'project_id' => $project->id,
                'repaired' => $repaired,
            ]);

            $this->finalizeProjectFromScenes($project->fresh());
        }

        return $repaired;
    }

    public function findGenerateSceneTaskForScene(AdstoryProject $project, AdstoryScene $scene): ?AdstoryAiTask
    {
        $task = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SCENE)
            ->where('taskable_id', $scene->id)
            ->orderByDesc('id')
            ->first();

        if ($task) {
            return $task;
        }

        if ($scene->scene_number === null) {
            return null;
        }

        $sceneNumber = (int) $scene->scene_number;

        return AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_SCENE)
            ->whereIn('status', [
                AdstoryAiTask::STATUS_COMPLETED,
                AdstoryAiTask::STATUS_FAILED,
            ])
            ->orderByDesc('id')
            ->get()
            ->first(function (AdstoryAiTask $candidate) use ($sceneNumber) {
                $payload = is_array($candidate->payload) ? $candidate->payload : [];

                return (int) ($payload['scene_number'] ?? 0) === $sceneNumber;
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function retryFailed(AdstoryProject $project): array
    {
        return $this->resumeGeneration($project, retryFailed: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function resumeGeneration(AdstoryProject $project, bool $retryFailed = false): array
    {
        if ($project->scene_generation_status === self::PROJECT_STATUS_RUNNING) {
            throw new RuntimeException('Scene generation is already running.');
        }

        $this->aiTaskService->resetStaleRunningTasks($project->id);

        if ($project->scene_generation_status === self::PROJECT_STATUS_CANCELLED) {
            $this->aiTaskService->resetCancelledSceneTasks($project->id);

            AdstoryScene::query()
                ->where('adstory_project_id', $project->id)
                ->where('status', self::SCENE_STATUS_QUEUED)
                ->get()
                ->each(fn (AdstoryScene $scene) => $this->aiTaskService->resetOrCreateSceneTask($project, $scene));
        }

        if ($retryFailed) {
            $this->aiTaskService->resetFailedSceneTasks($project->id);

            AdstoryScene::query()
                ->where('adstory_project_id', $project->id)
                ->where('status', self::SCENE_STATUS_FAILED)
                ->update([
                    'status' => self::SCENE_STATUS_QUEUED,
                    'generation_error' => null,
                    'generated_at' => null,
                ]);
        }

        AdstoryScene::query()
            ->where('adstory_project_id', $project->id)
            ->where('status', self::SCENE_STATUS_PENDING)
            ->update(['status' => self::SCENE_STATUS_QUEUED]);

        $project->update([
            'scene_generation_status' => self::PROJECT_STATUS_RUNNING,
            'scene_generation_finished_at' => null,
        ]);

        if ($this->aiTaskService->hasEligibleQueuedTasks($project->id)) {
            $this->aiTaskService->dispatchWorker();

            Log::info('Adstory scene-generation: resume dispatched', [
                'project_id' => $project->id,
                'retry_failed' => $retryFailed,
            ]);
        }

        return $this->buildProgressPayload($project->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function retryScene(AdstoryProject $project, AdstoryScene $scene): array
    {
        if ($scene->adstory_project_id !== $project->id) {
            throw new RuntimeException('Scene not found for this project.');
        }

        if ($scene->status === self::SCENE_STATUS_COMPLETED) {
            throw new RuntimeException('Completed scenes cannot be retried.');
        }

        DB::transaction(function () use ($project, $scene) {
            $scene->update([
                'status' => self::SCENE_STATUS_QUEUED,
                'generation_error' => null,
                'generated_at' => null,
            ]);

            $this->aiTaskService->resetOrCreateSceneTask($project, $scene);

            $project->update([
                'scene_generation_status' => self::PROJECT_STATUS_RUNNING,
                'scene_generation_finished_at' => null,
            ]);
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory scene-generation: scene retry dispatched', [
            'project_id' => $project->id,
            'scene_id' => $scene->id,
        ]);

        return $this->buildProgressPayload($project->fresh());
    }

    public function finalizeProjectFromScenes(AdstoryProject $project): void
    {
        $project->load(['scenes' => fn ($query) => $query->orderBy('order_index')->orderBy('id')]);

        $this->finalizeProjectIfDone($project, $project->scenes);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProgressPayload(AdstoryProject $project): array
    {
        $this->aiTaskService->resetStaleRunningTasks($project->id, AdstoryAiTask::TYPE_GENERATE_SCENE);
        $this->syncSceneStatusesFromTasks($project);

        $project->refresh()->load(['scenes' => fn ($query) => $query->orderBy('order_index')->orderBy('id')]);

        $scenes = $project->scenes
            ->sortBy(fn (AdstoryScene $scene) => [$scene->order_index, $scene->id])
            ->values();

        $total = $scenes->count();
        $completed = $scenes->where('status', self::SCENE_STATUS_COMPLETED)->count();
        $failed = $scenes->where('status', self::SCENE_STATUS_FAILED)->count();
        $running = $scenes->where('status', self::SCENE_STATUS_GENERATING)->count();
        $queued = $scenes->whereIn('status', [
            self::SCENE_STATUS_QUEUED,
            self::SCENE_STATUS_PENDING,
            'draft',
        ])->count();
        $remaining = $scenes->whereIn('status', [
            self::SCENE_STATUS_PENDING,
            self::SCENE_STATUS_QUEUED,
            self::SCENE_STATUS_GENERATING,
            'draft',
        ])->count();
        $progressPercent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        $taskCounts = $this->aiTaskService->getTaskCounts($project->id, AdstoryAiTask::TYPE_GENERATE_SCENE);

        if ($running === 0 && $taskCounts['running'] > 0) {
            $running = $taskCounts['running'];
        }

        $this->finalizeProjectIfDone($project, $scenes);
        $project->refresh();

        $status = $this->resolveProjectGenerationStatus($project, $scenes, $total, $completed, $failed, $queued, $running, $taskCounts);

        $controls = $this->buildGenerationControls($status, $project, $total, $queued, $taskCounts['queued']);

        $currentScene = $scenes->firstWhere('status', self::SCENE_STATUS_GENERATING)
            ?? $scenes->firstWhere('status', self::SCENE_STATUS_QUEUED)
            ?? $scenes->firstWhere('status', self::SCENE_STATUS_PENDING);

        $failedScenes = $scenes
            ->where('status', self::SCENE_STATUS_FAILED)
            ->map(fn (AdstoryScene $scene) => $scene->toFailedProgressArray())
            ->values()
            ->all();

        Log::info('Adstory scene-generation: progress calculated', [
            'project_id' => $project->id,
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'queued' => $queued,
            'remaining' => $remaining,
        ]);

        return [
            'success' => true,
            'status' => $status,
            'can_pause' => $controls['can_pause'],
            'can_resume' => $controls['can_resume'],
            'can_cancel' => $controls['can_cancel'],
            'can_restart' => $controls['can_restart'],
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'queued' => $queued,
            'remaining' => $remaining,
            'progress_percent' => $progressPercent,
            'current_scene' => $currentScene?->toProgressArray(),
            'failed_scenes' => $failedScenes,
            'tasks' => [
                'queued' => $taskCounts['queued'],
                'running' => $taskCounts['running'],
                'completed' => $taskCounts['completed'],
                'failed' => $taskCounts['failed'],
            ],
            'scenes' => $scenes
                ->map(fn (AdstoryScene $scene) => $scene->toApiArray())
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTaskSummary(AdstoryProject $project): array
    {
        $counts = $this->aiTaskService->getTaskCounts($project->id);

        return [
            'total' => array_sum($counts),
            'queued' => $counts['queued'],
            'running' => $counts['running'],
            'completed' => $counts['completed'],
            'failed' => $counts['failed'],
            'cancelled' => $counts['cancelled'],
        ];
    }

    /**
     * @param  array<string, int>  $taskQueued
     * @return array<string, bool>
     */
    private function buildGenerationControls(
        string $status,
        AdstoryProject $project,
        int $total,
        int $queuedScenes,
        int $taskQueued,
    ): array {
        $hasScreenplay = mb_strlen(trim((string) ($project->screenplay ?? ''))) >= 20;

        return [
            'can_pause' => $status === self::PROJECT_STATUS_RUNNING,
            'can_resume' => $status === self::PROJECT_STATUS_PAUSED
                || ($status === self::PROJECT_STATUS_CANCELLED && ($queuedScenes > 0 || $taskQueued > 0)),
            'can_cancel' => in_array($status, [
                self::PROJECT_STATUS_RUNNING,
                self::PROJECT_STATUS_PAUSED,
                self::PROJECT_STATUS_RESTARTING,
            ], true),
            'can_restart' => $hasScreenplay && ! in_array($status, [
                self::PROJECT_STATUS_RUNNING,
                self::PROJECT_STATUS_RESTARTING,
            ], true),
        ];
    }

    /**
     * @param  Collection<int, AdstoryScene>  $scenes
     */
    private function finalizeProjectIfDone(AdstoryProject $project, Collection $scenes): void
    {
        if (in_array($project->scene_generation_status, array_merge(
            self::BLOCKED_WORKER_STATUSES,
            [self::PROJECT_STATUS_PAUSED]
        ), true)) {
            return;
        }

        $total = $scenes->count();
        $completed = $scenes->where('status', self::SCENE_STATUS_COMPLETED)->count();
        $failedScenes = $scenes->where('status', self::SCENE_STATUS_FAILED)->count();
        $processed = $completed + $failedScenes;

        if ($total <= 0 || $processed < $total) {
            return;
        }

        $hasIncomplete = $scenes->contains(
            fn (AdstoryScene $scene) => ! in_array($scene->status, [self::SCENE_STATUS_COMPLETED, self::SCENE_STATUS_FAILED], true)
        );

        if ($hasIncomplete) {
            return;
        }

        $status = $failedScenes > 0
            ? self::PROJECT_STATUS_COMPLETED_WITH_ERRORS
            : self::PROJECT_STATUS_COMPLETED;

        $project->update([
            'scene_generation_status' => $status,
            'scene_generation_total' => $total,
            'scene_generation_completed' => $completed,
            'scene_generation_failed' => $failedScenes,
            'scene_generation_finished_at' => now(),
        ]);

        Log::info('Adstory scene-generation: batch finished', [
            'project_id' => $project->id,
            'status' => $status,
            'completed' => $completed,
            'failed' => $failedScenes,
            'total' => $total,
        ]);
    }

    /**
     * Derive project generation status from live scene rows — never from stored counters alone.
     *
     * @param  array<string, int>  $taskCounts
     */
    private function resolveProjectGenerationStatus(
        AdstoryProject $project,
        Collection $scenes,
        int $total,
        int $completed,
        int $failed,
        int $queued,
        int $running,
        array $taskCounts,
    ): string {
        $stored = $project->scene_generation_status ?? self::PROJECT_STATUS_IDLE;

        if (in_array($stored, array_merge(self::BLOCKED_WORKER_STATUSES, [self::PROJECT_STATUS_PAUSED]), true)) {
            return $stored;
        }

        if ($stored === self::PROJECT_STATUS_CANCELLED) {
            return self::PROJECT_STATUS_CANCELLED;
        }

        if ($total > 0) {
            $hasIncomplete = $scenes->contains(
                fn (AdstoryScene $scene) => ! in_array($scene->status, [self::SCENE_STATUS_COMPLETED, self::SCENE_STATUS_FAILED], true)
            );

            if (! $hasIncomplete) {
                return $failed > 0
                    ? self::PROJECT_STATUS_COMPLETED_WITH_ERRORS
                    : self::PROJECT_STATUS_COMPLETED;
            }
        }

        if ($queued > 0 || $running > 0 || $taskCounts['queued'] > 0 || $taskCounts['running'] > 0) {
            return self::PROJECT_STATUS_RUNNING;
        }

        return $stored;
    }

    private function buildScenePlanPrompt(string $screenplay, ?string $style): string
    {
        $styleInstruction = $style
            ? "The video storyboard style is: {$style}."
            : 'Use a cinematic storyboard-friendly style suitable for video production.';

        return <<<PROMPT
You are a professional production breakdown artist. Analyze the following screenplay and create a scene plan only.

Rules:
- Do not change the story meaning.
- Do not add new plot points.
- Preserve the original order.
- Each scene should represent one clear story moment or location/time change.
- Return a lightweight plan only — no full descriptions yet.
- Return only valid JSON.
- No markdown.
- No explanation.
- No text before or after JSON.

{$styleInstruction}

JSON format must be exactly:

[
  {
    "scene_number": 1,
    "title": "Scene title",
    "location": "Main location",
    "time_of_day": "Day / Night / Sunset / Morning / Unknown",
    "summary": "Short purpose or summary of this scene"
  }
]

Screenplay:
{$screenplay}
PROMPT;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseScenePlanJson(string $text): array
    {
        $json = $this->extractJsonArray($text);
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse scene plan from Gemini response: invalid JSON.');
        }

        if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === []) {
            throw new RuntimeException('Failed to parse scene plan from Gemini response: expected a non-empty JSON array.');
        }

        return array_values(array_map(function (array $item, int $index) {
            return [
                'scene_number' => (int) ($item['scene_number'] ?? ($index + 1)),
                'title' => (string) ($item['title'] ?? 'Untitled scene'),
                'location' => (string) ($item['location'] ?? ''),
                'time_of_day' => (string) ($item['time_of_day'] ?? 'Unknown'),
                'summary' => (string) ($item['summary'] ?? ($item['purpose'] ?? '')),
            ];
        }, $decoded, array_keys($decoded)));
    }

    private function extractJsonArray(string $text): string
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $text, $matches)) {
            return trim($matches[1]);
        }

        $start = strpos($text, '[');
        $end = strrpos($text, ']');

        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }
}
