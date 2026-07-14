<?php

namespace App\Services\Adstory;

use App\Models\AdstoryAiTask;
use App\Models\AdstoryCharacter;
use App\Models\AdstoryProject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdstoryCharacterGenerationService
{
    public const IMAGE_STATUS_QUEUED = 'queued';

    public const IMAGE_STATUS_GENERATING = 'generating';

    public const IMAGE_STATUS_COMPLETED = 'completed';

    public const IMAGE_STATUS_FAILED = 'failed';

    public const PROJECT_STATUS_IDLE = 'idle';

    public const PROJECT_STATUS_RUNNING = 'running';

    public const PROJECT_STATUS_COMPLETED = 'completed';

    public const PROJECT_STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const PROJECT_STATUS_FAILED = 'failed';

    public const PROJECT_STATUS_CANCELLED = 'cancelled';

    public function __construct(
        private readonly AdstoryAiTaskService $aiTaskService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startGeneration(AdstoryProject $project, ?string $style = null): array
    {
        $this->assertScreenplayOrScenes($project);

        if ($project->characters()->exists()) {
            $created = $this->ensureMissingImageTasks($project, $style);

            if ($created > 0) {
                $project->update([
                    'character_generation_status' => self::PROJECT_STATUS_RUNNING,
                    'character_generation_started_at' => $project->character_generation_started_at ?? now(),
                    'character_generation_finished_at' => null,
                    'current_step' => 'characters',
                ]);

                $this->aiTaskService->dispatchWorker();

                Log::info('Adstory character-generation: image tasks created for existing characters', [
                    'project_id' => $project->id,
                    'tasks_created' => $created,
                ]);

                return array_merge(
                    $this->buildProgressPayload($project->fresh()),
                    ['started' => true, 'tasks_created' => $created],
                );
            }

            Log::info('Adstory character-generation: skipped — characters already exist', [
                'project_id' => $project->id,
            ]);

            return array_merge(
                $this->buildProgressPayload($project->fresh()),
                ['started' => false],
            );
        }

        if (AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_EXTRACT_CHARACTERS)
            ->exists()) {
            return array_merge(
                $this->buildProgressPayload($project->fresh()),
                ['started' => false],
            );
        }

        DB::transaction(function () use ($project, $style) {
            $this->aiTaskService->createTask(
                project: $project,
                type: AdstoryAiTask::TYPE_EXTRACT_CHARACTERS,
                taskable: $project,
                payload: [
                    'project_id' => $project->id,
                    'style' => $style ?? $project->visual_style,
                ],
                priority: 8000,
            );

            $project->update([
                'character_generation_status' => self::PROJECT_STATUS_RUNNING,
                'character_generation_total' => 0,
                'character_generation_completed' => 0,
                'character_generation_failed' => 0,
                'character_generation_started_at' => now(),
                'character_generation_finished_at' => null,
                'current_step' => 'characters',
            ]);
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory character-generation: extract task created and worker dispatched', [
            'project_id' => $project->id,
        ]);

        return array_merge(
            $this->buildProgressPayload($project->fresh()),
            ['started' => true],
        );
    }

    /**
     * Queue one generate_character_image task per character after extraction.
     */
    public function queueImageGenerationTasks(AdstoryProject $project, ?string $style = null): void
    {
        $style = $style ?? $project->visual_style;

        $characters = AdstoryCharacter::query()
            ->where('adstory_project_id', $project->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        if ($characters->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($project, $characters, $style) {
            $queued = 0;

            foreach ($characters as $index => $character) {
                if ($this->resolveImageStatus($character) === self::IMAGE_STATUS_COMPLETED) {
                    continue;
                }

                $hasPendingTask = AdstoryAiTask::query()
                    ->where('adstory_project_id', $project->id)
                    ->where('type', AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE)
                    ->where('taskable_id', $character->id)
                    ->whereIn('status', [
                        AdstoryAiTask::STATUS_QUEUED,
                        AdstoryAiTask::STATUS_RUNNING,
                    ])
                    ->exists();

                if ($hasPendingTask) {
                    continue;
                }

                $character->markImageGenerationQueued();

                $this->aiTaskService->createTask(
                    project: $project,
                    type: AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE,
                    taskable: $character,
                    payload: [
                        'project_id' => $project->id,
                        'character_id' => $character->id,
                        'style' => $style,
                    ],
                    priority: 7000 - $index,
                );

                $queued++;
            }

            $project->update([
                'character_generation_total' => $characters->count(),
            ]);

            Log::info('Adstory character-generation: image tasks queued', [
                'project_id' => $project->id,
                'characters_count' => $characters->count(),
                'tasks_queued' => $queued,
            ]);
        });

        $this->aiTaskService->dispatchWorker();
    }

    /**
     * @return array<string, mixed>
     */
    public function getProgress(AdstoryProject $project): array
    {
        return $this->buildProgressPayload($project);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    public function cancelGeneration(AdstoryProject $project): array
    {
        if (! in_array($project->character_generation_status, [
            self::PROJECT_STATUS_RUNNING,
        ], true)) {
            throw new RuntimeException('No active character generation to cancel.');
        }

        $cancelledCount = $this->aiTaskService->cancelQueuedTasksByTypes($project->id, [
            AdstoryAiTask::TYPE_EXTRACT_CHARACTERS,
            AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE,
        ]);

        $project->update([
            'character_generation_status' => self::PROJECT_STATUS_CANCELLED,
            'character_generation_finished_at' => now(),
        ]);

        Log::info('Adstory character-generation: cancelled', [
            'project_id' => $project->id,
            'cancelled_tasks' => $cancelledCount,
        ]);

        return $this->buildProgressPayload($project->fresh());
    }

    public function resumeGeneration(AdstoryProject $project, bool $retryFailed = false, ?string $style = null): array
    {
        $staleReset = $this->aiTaskService->resetStaleRunningTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE
        );

        if ($staleReset > 0) {
            Log::warning('Adstory character-generation: stale tasks reset during resume', [
                'project_id' => $project->id,
                'count' => $staleReset,
            ]);
        }

        $this->resetStuckGeneratingCharacters($project);

        if ($retryFailed) {
            $this->aiTaskService->resetFailedCharacterImageTasks($project->id);
        }

        $created = $this->ensureMissingImageTasks($project, $style);

        if (
            $created > 0 ||
            $project->character_generation_status === self::PROJECT_STATUS_CANCELLED
        ) {
            $project->update([
                'character_generation_status' => self::PROJECT_STATUS_RUNNING,
                'character_generation_finished_at' => null,
            ]);
        }

        Log::info('Adstory character-generation: resume dispatched', [
            'project_id' => $project->id,
            'retry_failed' => $retryFailed,
            'tasks_created' => $created,
        ]);

        $this->aiTaskService->dispatchWorker();

        return array_merge(
            $this->buildProgressPayload($project->fresh()),
            ['resumed' => true, 'tasks_created' => $created],
        );
    }

    /**
     * Create image tasks for incomplete characters that have no pending task.
     */
    public function ensureMissingImageTasks(AdstoryProject $project, ?string $style = null): int
    {
        $style = $style ?? $project->visual_style;
        $created = 0;

        $characters = AdstoryCharacter::query()
            ->where('adstory_project_id', $project->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        foreach ($characters as $index => $character) {
            if ($this->resolveImageStatus($character) === self::IMAGE_STATUS_COMPLETED) {
                continue;
            }

            $hasPendingTask = AdstoryAiTask::query()
                ->where('adstory_project_id', $project->id)
                ->where('type', AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE)
                ->where('taskable_id', $character->id)
                ->whereIn('status', [
                    AdstoryAiTask::STATUS_QUEUED,
                    AdstoryAiTask::STATUS_RUNNING,
                ])
                ->exists();

            if ($hasPendingTask) {
                continue;
            }

            $character->markImageGenerationQueued();
            $character->update(['generation_error' => null]);

            $this->aiTaskService->createTask(
                project: $project,
                type: AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE,
                taskable: $character,
                payload: [
                    'project_id' => $project->id,
                    'character_id' => $character->id,
                    'style' => $style,
                ],
                priority: 7000 - $index,
            );

            $created++;
        }

        if ($created > 0) {
            $project->update([
                'character_generation_total' => $characters->count(),
            ]);
        }

        return $created;
    }

    private function resetStuckGeneratingCharacters(AdstoryProject $project): void
    {
        AdstoryCharacter::query()
            ->where('adstory_project_id', $project->id)
            ->where('image_status', self::IMAGE_STATUS_GENERATING)
            ->each(function (AdstoryCharacter $character) use ($project) {
                $hasRunningTask = AdstoryAiTask::query()
                    ->where('adstory_project_id', $project->id)
                    ->where('type', AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE)
                    ->where('taskable_id', $character->id)
                    ->where('status', AdstoryAiTask::STATUS_RUNNING)
                    ->exists();

                if (! $hasRunningTask) {
                    $character->markImageGenerationQueued();
                }
            });
    }

    public function finalizeProjectFromCharacters(AdstoryProject $project): void
    {
        $project->load(['characters' => fn ($query) => $query->orderBy('order_index')->orderBy('id')]);

        $this->finalizeProjectIfDone($project, $project->characters);
    }

    private function assertScreenplayOrScenes(AdstoryProject $project): void
    {
        $hasScreenplay = mb_strlen(trim((string) ($project->screenplay ?? ''))) >= 20;
        $hasScenes = $project->scenes()
            ->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)
            ->exists();

        if (! $hasScreenplay && ! $hasScenes) {
            throw new RuntimeException('Project must have a screenplay or completed scenes before starting character generation.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function detectStalledState(AdstoryProject $project, Collection $characters): array
    {
        $queuedTasks = $this->aiTaskService->countEligibleQueuedTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE
        );
        $runningTasks = $this->aiTaskService->countRunningTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE
        );
        $hasStale = $this->aiTaskService->hasStaleRunningTasks(
            $project->id,
            AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE
        );

        $incompleteCharacters = $characters->filter(
            fn (AdstoryCharacter $character) => $this->resolveImageStatus($character) !== self::IMAGE_STATUS_COMPLETED
        );

        $missingTasks = $incompleteCharacters->filter(function (AdstoryCharacter $character) use ($project) {
            return ! AdstoryAiTask::query()
                ->where('adstory_project_id', $project->id)
                ->where('type', AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE)
                ->where('taskable_id', $character->id)
                ->whereIn('status', [
                    AdstoryAiTask::STATUS_QUEUED,
                    AdstoryAiTask::STATUS_RUNNING,
                ])
                ->exists();
        })->count();

        $stalled = $hasStale
            || ($queuedTasks > 0 && $runningTasks === 0)
            || ($incompleteCharacters->isNotEmpty() && $missingTasks > 0 && $runningTasks === 0);

        if ($stalled) {
            Log::warning('Adstory character-generation: stalled state detected', [
                'project_id' => $project->id,
                'queued_tasks' => $queuedTasks,
                'running_tasks' => $runningTasks,
                'missing_tasks' => $missingTasks,
                'has_stale' => $hasStale,
            ]);
        }

        return [
            'stalled' => $stalled,
            'queued_tasks' => $queuedTasks,
            'running_tasks' => $runningTasks,
            'missing_tasks' => $missingTasks,
        ];
    }

    /**
     * @param  Collection<int, AdstoryCharacter>  $characters
     * @return list<array<string, mixed>>
     */
    private function buildFailedCharactersList(Collection $characters): array
    {
        return $characters
            ->filter(fn (AdstoryCharacter $character) => $this->resolveImageStatus($character) === self::IMAGE_STATUS_FAILED)
            ->map(fn (AdstoryCharacter $character) => [
                'id' => $character->id,
                'name' => $character->name,
                'image_status' => $character->image_status,
                'generation_error' => $character->generation_error,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProgressPayload(AdstoryProject $project): array
    {
        $this->aiTaskService->resetStaleRunningTasks($project->id, AdstoryAiTask::TYPE_EXTRACT_CHARACTERS);
        $this->aiTaskService->resetStaleRunningTasks($project->id, AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE);
        $this->resetStuckGeneratingCharacters($project);

        $project->refresh()->load([
            'characters' => fn ($query) => $query->orderBy('order_index')->orderBy('id'),
        ]);

        $characters = $project->characters->values();
        $extractCounts = $this->aiTaskService->getTaskCounts($project->id, AdstoryAiTask::TYPE_EXTRACT_CHARACTERS);
        $imageCounts = $this->aiTaskService->getTaskCounts($project->id, AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE);

        if ($characters->isEmpty()) {
            $progress = $this->buildExtractionPhaseProgress($project, $extractCounts);
        } else {
            $progress = $this->buildImagePhaseProgress($project, $characters, $imageCounts);
        }

        $this->finalizeProjectIfDone($project, $characters);
        $project->refresh();
        $characters = $project->characters->values();

        $stall = $this->detectStalledState($project, $characters);

        $status = $project->character_generation_status ?? self::PROJECT_STATUS_IDLE;

        if (
            $status === self::PROJECT_STATUS_IDLE
            && $characters->isNotEmpty()
            && ($progress['completed'] ?? 0) === ($progress['total'] ?? 0)
            && ($progress['total'] ?? 0) > 0
        ) {
            $status = self::PROJECT_STATUS_COMPLETED;
        }

        $characterRows = $characters
            ->map(fn (AdstoryCharacter $character) => $character->toApiArray())
            ->values()
            ->all();

        return array_merge($progress, [
            'success' => true,
            'project_id' => $project->id,
            'status' => $status,
            'stalled' => $stall['stalled'],
            'queued_tasks' => $stall['queued_tasks'],
            'running_tasks' => $stall['running_tasks'],
            'missing_tasks' => $stall['missing_tasks'],
            'failed_characters' => $this->buildFailedCharactersList($characters),
            'project' => $project->toApiArray(),
            'characters' => $characterRows,
            'extraction' => [
                'queued' => $extractCounts['queued'],
                'running' => $extractCounts['running'],
                'completed' => $extractCounts['completed'],
                'failed' => $extractCounts['failed'],
            ],
            'tasks' => [
                'queued' => $imageCounts['queued'],
                'running' => $imageCounts['running'],
                'completed' => $imageCounts['completed'],
                'failed' => $imageCounts['failed'],
            ],
        ]);
    }

    /**
     * @param  array<string, int>  $extractCounts
     * @return array<string, mixed>
     */
    private function buildExtractionPhaseProgress(AdstoryProject $project, array $extractCounts): array
    {
        $total = 1;
        $completed = $extractCounts['completed'] > 0 ? 1 : 0;
        $failed = $extractCounts['failed'] > 0 ? 1 : 0;
        $running = $extractCounts['running'];
        $queued = $extractCounts['queued'];
        $remaining = max(0, 1 - $completed - $failed);
        $progressPercent = $completed > 0 ? 100 : 0;

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'queued' => $queued,
            'remaining' => $remaining,
            'progress_percent' => $progressPercent,
            'estimated_remaining' => $remaining > 0 ? null : 0,
            'current_character' => null,
            'phase' => 'extraction',
        ];
    }

    /**
     * @param  Collection<int, AdstoryCharacter>  $characters
     * @param  array<string, int>  $imageCounts
     * @return array<string, mixed>
     */
    private function buildImagePhaseProgress(AdstoryProject $project, Collection $characters, array $imageCounts): array
    {
        $total = $characters->count();
        $completed = $this->countCharactersByImageStatus($characters, self::IMAGE_STATUS_COMPLETED);
        $failed = $this->countCharactersByImageStatus($characters, self::IMAGE_STATUS_FAILED);
        $running = $this->countCharactersByImageStatus($characters, self::IMAGE_STATUS_GENERATING);
        $queued = $this->countCharactersByImageStatus($characters, self::IMAGE_STATUS_QUEUED);
        $remaining = $characters->filter(
            fn (AdstoryCharacter $character) => ! in_array(
                $this->resolveImageStatus($character),
                [self::IMAGE_STATUS_COMPLETED, self::IMAGE_STATUS_FAILED],
                true
            )
        )->count();

        if ($running === 0 && $imageCounts['running'] > 0) {
            $running = $imageCounts['running'];
        }

        $progressPercent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
        $estimatedRemaining = $this->estimateRemainingSeconds($project->id, $remaining);

        $currentCharacter = $characters->first(
            fn (AdstoryCharacter $character) => $this->resolveImageStatus($character) === self::IMAGE_STATUS_GENERATING
        ) ?? $characters->first(
            fn (AdstoryCharacter $character) => $this->resolveImageStatus($character) === self::IMAGE_STATUS_QUEUED
        );

        Log::info('Adstory character-generation: progress calculated', [
            'project_id' => $project->id,
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'remaining' => $remaining,
        ]);

        return [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'queued' => $queued,
            'remaining' => $remaining,
            'progress_percent' => $progressPercent,
            'estimated_remaining' => $estimatedRemaining,
            'current_character' => $currentCharacter ? $this->toCharacterProgress($currentCharacter) : null,
            'phase' => 'images',
        ];
    }

    /**
     * @param  Collection<int, AdstoryCharacter>  $characters
     */
    private function finalizeProjectIfDone(AdstoryProject $project, Collection $characters): void
    {
        if ($characters->isNotEmpty()) {
            $total = $characters->count();
            $completed = $this->countCharactersByImageStatus($characters, self::IMAGE_STATUS_COMPLETED);
            $failed = $this->countCharactersByImageStatus($characters, self::IMAGE_STATUS_FAILED);
            $incomplete = $total - $completed - $failed;

            if ($incomplete > 0 && in_array($project->character_generation_status, [
                self::PROJECT_STATUS_COMPLETED,
                self::PROJECT_STATUS_COMPLETED_WITH_ERRORS,
            ], true)) {
                $project->update([
                    'character_generation_status' => self::PROJECT_STATUS_RUNNING,
                    'character_generation_finished_at' => null,
                ]);
            }
        }

        if (in_array($project->character_generation_status, [
            self::PROJECT_STATUS_COMPLETED,
            self::PROJECT_STATUS_COMPLETED_WITH_ERRORS,
            self::PROJECT_STATUS_FAILED,
        ], true)) {
            $project->refresh();

            if ($characters->isEmpty()) {
                return;
            }

            $total = $characters->count();
            $completed = $this->countCharactersByImageStatus($characters, self::IMAGE_STATUS_COMPLETED);
            $failed = $this->countCharactersByImageStatus($characters, self::IMAGE_STATUS_FAILED);

            if (($completed + $failed) >= $total) {
                return;
            }
        }

        $hasActiveExtract = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_EXTRACT_CHARACTERS)
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActiveExtract) {
            return;
        }

        if ($characters->isEmpty()) {
            if ($project->character_generation_status !== self::PROJECT_STATUS_RUNNING) {
                return;
            }

            $extractFailed = AdstoryAiTask::query()
                ->where('adstory_project_id', $project->id)
                ->where('type', AdstoryAiTask::TYPE_EXTRACT_CHARACTERS)
                ->where('status', AdstoryAiTask::STATUS_FAILED)
                ->exists();

            if ($extractFailed) {
                $project->update([
                    'character_generation_status' => self::PROJECT_STATUS_FAILED,
                    'character_generation_finished_at' => now(),
                ]);
            }

            return;
        }

        $hasActiveImageTasks = AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE)
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();

        if ($hasActiveImageTasks) {
            return;
        }

        $total = $characters->count();
        $completed = $this->countCharactersByImageStatus($characters, self::IMAGE_STATUS_COMPLETED);
        $failed = $this->countCharactersByImageStatus($characters, self::IMAGE_STATUS_FAILED);

        if (($completed + $failed) < $total) {
            return;
        }

        if ($project->character_generation_status !== self::PROJECT_STATUS_RUNNING
            && ! in_array($project->character_generation_status, [null, self::PROJECT_STATUS_IDLE], true)) {
            return;
        }

        $status = $failed > 0
            ? self::PROJECT_STATUS_COMPLETED_WITH_ERRORS
            : self::PROJECT_STATUS_COMPLETED;

        $project->update([
            'character_generation_status' => $status,
            'character_generation_total' => $total,
            'character_generation_completed' => $completed,
            'character_generation_failed' => $failed,
            'character_generation_finished_at' => $project->character_generation_finished_at ?? now(),
            'character_generation_started_at' => $project->character_generation_started_at ?? now(),
        ]);

        Log::info('Adstory character-generation: batch finished', [
            'project_id' => $project->id,
            'status' => $status,
            'completed' => $completed,
            'failed' => $failed,
            'total' => $total,
        ]);
    }

    /**
     * @param  Collection<int, AdstoryCharacter>  $characters
     */
    private function countCharactersByImageStatus(Collection $characters, string $status): int
    {
        return $characters
            ->filter(fn (AdstoryCharacter $character) => $this->resolveImageStatus($character) === $status)
            ->count();
    }

    private function resolveImageStatus(AdstoryCharacter $character): ?string
    {
        $status = $character->image_status;

        if ($status === 'pending') {
            return self::IMAGE_STATUS_QUEUED;
        }

        if (($status === null || $status === '') && ! empty($character->image_url)) {
            return self::IMAGE_STATUS_COMPLETED;
        }

        return $status;
    }

    private function estimateRemainingSeconds(int $projectId, int $remaining): ?int
    {
        if ($remaining <= 0) {
            return 0;
        }

        $durations = AdstoryAiTask::query()
            ->where('adstory_project_id', $projectId)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_CHARACTER_IMAGE)
            ->where('status', AdstoryAiTask::STATUS_COMPLETED)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->get()
            ->map(fn (AdstoryAiTask $task) => $task->completed_at->diffInSeconds($task->started_at))
            ->filter(fn (int $seconds) => $seconds > 0);

        if ($durations->isEmpty()) {
            return null;
        }

        return (int) round($durations->avg()) * $remaining;
    }

    /**
     * @return array<string, mixed>
     */
    private function toCharacterProgress(AdstoryCharacter $character): array
    {
        return [
            'id' => $character->id,
            'db_id' => $character->id,
            'name' => $character->name,
            'image_status' => $this->resolveImageStatus($character),
        ];
    }
}
