<?php

namespace App\Services\Adstory;

use App\Models\AdstoryAiTask;
use App\Models\AdstoryEpisode;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AdstoryEpisodeSceneGenerationService
{
    public function __construct(
        private readonly AdstoryAiTaskService $aiTaskService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startGeneration(AdstoryProject $project, AdstoryEpisode $episode, bool $force = false, ?string $style = null): array
    {
        $this->assertEpisodeBelongsToProject($episode, $project);

        if ($episode->scenes()->exists() && ! $force) {
            return array_merge(
                $this->buildSceneProgress($episode),
                ['started' => false],
            );
        }

        if ($this->hasActiveEpisodeSceneTask($project, $episode) && ! $force) {
            return array_merge(
                $this->buildSceneProgress($episode),
                ['started' => false],
            );
        }

        DB::transaction(function () use ($project, $episode, $force, $style) {
            if ($force) {
                $episode->scenes()->delete();
                AdstoryAiTask::query()
                    ->where('adstory_project_id', $project->id)
                    ->where('type', AdstoryAiTask::TYPE_GENERATE_EPISODE_SCENES)
                    ->where('taskable_id', $episode->id)
                    ->delete();
            }

            $episode->update([
                'status' => AdstoryEpisode::STATUS_SCENES_GENERATING,
                'scene_generation_status' => 'generating',
                'scene_generation_error' => null,
            ]);

            $this->aiTaskService->createTask(
                project: $project,
                type: AdstoryAiTask::TYPE_GENERATE_EPISODE_SCENES,
                taskable: $episode,
                payload: [
                    'project_id' => $project->id,
                    'episode_id' => $episode->id,
                    'start_scene_number' => $episode->start_scene_number,
                    'end_scene_number' => $episode->end_scene_number,
                    'style' => $style ?? $project->visual_style,
                ],
                priority: 8500,
            );

            $project->update(['current_step' => 'episodes']);
        });

        $this->aiTaskService->dispatchWorker();

        Log::info('Adstory episode scene-generation: task created', [
            'project_id' => $project->id,
            'episode_id' => $episode->id,
        ]);

        return array_merge(
            $this->buildSceneProgress($episode->fresh()),
            ['started' => true],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSceneProgress(AdstoryEpisode $episode): array
    {
        $episode->load(['scenes' => fn ($query) => $query
            ->select([
                'id', 'adstory_episode_id', 'adstory_project_id', 'scene_number', 'title',
                'location', 'environment', 'time_of_day', 'description', 'mood',
                'visual_style', 'status', 'shot_generation_status', 'order_index', 'meta',
            ])
            ->orderBy('order_index')
            ->orderBy('id'),
        ]);

        $scenes = $episode->scenes;
        $total = max($episode->estimated_scene_count, $scenes->count());
        $completed = $scenes->where('status', AdstorySceneGenerationService::SCENE_STATUS_COMPLETED)->count();
        $failed = $scenes->where('status', AdstorySceneGenerationService::SCENE_STATUS_FAILED)->count();
        $remaining = max(0, $total - $completed - $failed);
        $progressPercent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        return [
            'success' => true,
            'episode' => $episode->toApiArray(),
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'remaining' => $remaining,
            'progress_percent' => $progressPercent,
            'scenes' => $scenes->map(fn (AdstoryScene $scene) => [
                'id' => $scene->id,
                'scene_number' => $scene->scene_number,
                'title' => $scene->title,
                'location' => $scene->location,
                'environment' => $scene->environment,
                'time_of_day' => $scene->time_of_day,
                'description' => $scene->description,
                'mood' => $scene->mood,
                'visual_style' => $scene->visual_style,
                'status' => $scene->status,
                'shot_generation_status' => $scene->shot_generation_status,
                'order_index' => $scene->order_index,
                'meta' => $scene->meta ?? [],
            ])->values()->all(),
        ];
    }

    public function markEpisodeScenesCompleted(AdstoryEpisode $episode): void
    {
        $episode->markSceneGenerationCompleted();
    }

    public function markEpisodeScenesFailed(AdstoryEpisode $episode, string $error): void
    {
        $episode->markSceneGenerationFailed($error);
    }

    private function assertEpisodeBelongsToProject(AdstoryEpisode $episode, AdstoryProject $project): void
    {
        if ($episode->adstory_project_id !== $project->id) {
            throw new RuntimeException('Episode not found for this project.');
        }
    }

    private function hasActiveEpisodeSceneTask(AdstoryProject $project, AdstoryEpisode $episode): bool
    {
        return AdstoryAiTask::query()
            ->where('adstory_project_id', $project->id)
            ->where('type', AdstoryAiTask::TYPE_GENERATE_EPISODE_SCENES)
            ->where('taskable_id', $episode->id)
            ->whereIn('status', [AdstoryAiTask::STATUS_QUEUED, AdstoryAiTask::STATUS_RUNNING])
            ->exists();
    }
}
