<?php

namespace App\Console\Commands;

use App\Models\AdstoryProject;
use App\Services\Adstory\AdstorySceneGenerationService;
use Illuminate\Console\Command;

class SyncAdstorySceneStatusCommand extends Command
{
    protected $signature = 'adstory:sync-scene-status {project? : Project ID to repair (omit for all projects)}';

    protected $description = 'Reconcile adstory_scenes.status from completed/failed generate_scene AI tasks';

    public function handle(AdstorySceneGenerationService $sceneGenerationService): int
    {
        $projectId = $this->argument('project');

        $query = AdstoryProject::query()->orderBy('id');

        if ($projectId !== null) {
            $query->where('id', (int) $projectId);
        }

        $projects = $query->get();

        if ($projects->isEmpty()) {
            $this->error('No matching projects found.');

            return self::FAILURE;
        }

        $totalRepaired = 0;

        foreach ($projects as $project) {
            $repaired = $sceneGenerationService->syncSceneStatusesFromTasks($project);
            $totalRepaired += $repaired;

            $this->line("Project {$project->id}: repaired {$repaired} scene(s).");
        }

        $this->info("Done. Repaired {$totalRepaired} scene(s) across {$projects->count()} project(s).");

        return self::SUCCESS;
    }
}
