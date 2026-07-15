<?php

namespace App\Console\Commands;

use App\Models\AdstoryProject;
use App\Services\Adstory\AdstoryProjectCoverService;
use Illuminate\Console\Command;

class BackfillProjectCoversCommand extends Command
{
    protected $signature = 'adstory:backfill-project-covers
                            {project? : Optional project ID to backfill}
                            {--force : Regenerate covers even when one already exists}';

    protected $description = 'Generate missing story cover images for project cards';

    public function handle(AdstoryProjectCoverService $coverService): int
    {
        $projectId = $this->argument('project');
        $force = (bool) $this->option('force');

        if ($projectId !== null) {
            $project = AdstoryProject::query()->find((int) $projectId);

            if (! $project) {
                $this->error("Project {$projectId} not found.");

                return self::FAILURE;
            }

            $this->info("Backfilling cover for project {$projectId} ({$project->title})...");
        } else {
            $this->info('Backfilling covers for projects missing a cover image...');
        }

        $result = $coverService->backfillMissingCovers(
            projectId: $projectId !== null ? (int) $projectId : null,
            force: $force,
        );

        foreach ($result['errors'] as $error) {
            $this->warn("  Project {$error['project_id']}: {$error['message']}");
        }

        $this->info("Done. Generated {$result['generated']}, skipped {$result['skipped']}, failed {$result['failed']}.");

        return $result['failed'] > 0 && $result['generated'] === 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
