<?php

namespace App\Services\Adstory;

use App\Models\AdstoryEpisode;
use App\Models\AdstoryProject;
use App\Models\AdstoryScene;
use App\Models\AdstoryShot;
use Illuminate\Support\Facades\DB;

class AdstoryEpisodeLoaderService
{
    /**
     * @return array<string, mixed>
     */
    public function show(AdstoryProject $project, AdstoryEpisode $episode): array
    {
        $this->assertBelongsToProject($episode, $project);

        $sceneCount = $episode->scenes()->count();
        $shotCount = AdstoryShot::query()
            ->whereIn('adstory_scene_id', $episode->scenes()->select('id'))
            ->count();

        return [
            'success' => true,
            'episode' => $episode->toApiArray(),
            'scene_count' => $sceneCount,
            'shot_count' => $shotCount,
            'progress_summary' => [
                'scene_generation_status' => $episode->scene_generation_status,
                'shot_generation_status' => $episode->shot_generation_status,
                'status' => $episode->status,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function projectEpisodeCounts(int $projectId): array
    {
        $episodes = AdstoryEpisode::query()
            ->where('adstory_project_id', $projectId)
            ->select('id')
            ->get();

        if ($episodes->isEmpty()) {
            return [];
        }

        $episodeIds = $episodes->pluck('id');

        $sceneCounts = AdstoryScene::query()
            ->whereIn('adstory_episode_id', $episodeIds)
            ->select('adstory_episode_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('adstory_episode_id')
            ->pluck('aggregate', 'adstory_episode_id');

        $shotCounts = AdstoryShot::query()
            ->whereIn('adstory_scene_id', function ($query) use ($episodeIds) {
                $query->select('id')
                    ->from('adstory_scenes')
                    ->whereIn('adstory_episode_id', $episodeIds);
            })
            ->join('adstory_scenes', 'adstory_scenes.id', '=', 'adstory_shots.adstory_scene_id')
            ->select('adstory_scenes.adstory_episode_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('adstory_scenes.adstory_episode_id')
            ->pluck('aggregate', 'adstory_episode_id');

        return [
            'scene_counts' => $sceneCounts->all(),
            'shot_counts' => $shotCounts->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function episodeSummariesForProject(int $projectId): array
    {
        $counts = $this->projectEpisodeCounts($projectId);

        return AdstoryEpisode::query()
            ->where('adstory_project_id', $projectId)
            ->orderBy('episode_number')
            ->get()
            ->map(fn (AdstoryEpisode $episode) => $episode->toSummaryArray(
                sceneCount: (int) ($counts['scene_counts'][$episode->id] ?? 0),
                shotCount: (int) ($counts['shot_counts'][$episode->id] ?? 0),
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    public function projectCounts(int $projectId): array
    {
        return [
            'episodes' => AdstoryEpisode::query()->where('adstory_project_id', $projectId)->count(),
            'scenes' => AdstoryScene::query()->where('adstory_project_id', $projectId)->count(),
            'shots' => AdstoryShot::query()->where('adstory_project_id', $projectId)->count(),
        ];
    }

    private function assertBelongsToProject(AdstoryEpisode $episode, AdstoryProject $project): void
    {
        if ($episode->adstory_project_id !== $project->id) {
            abort(404, 'Episode not found for this project.');
        }
    }
}
