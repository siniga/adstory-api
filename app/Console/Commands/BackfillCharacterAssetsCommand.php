<?php

namespace App\Console\Commands;

use App\Models\AdstoryCharacter;
use App\Models\AdstoryProject;
use App\Services\Adstory\AdstoryCharacterAssetService;
use Illuminate\Console\Command;

class BackfillCharacterAssetsCommand extends Command
{
    protected $signature = 'adstory:backfill-character-assets {project? : Optional project ID to limit backfill}';

    protected $description = 'Backfill adstory_character_assets from character.references JSON and hero image_url';

    public function handle(AdstoryCharacterAssetService $assetService): int
    {
        $projectId = $this->argument('project');

        $query = AdstoryCharacter::query()->orderBy('id');

        if ($projectId) {
            $query->where('adstory_project_id', (int) $projectId);
            $project = AdstoryProject::query()->find((int) $projectId);
            if (! $project) {
                $this->error("Project {$projectId} not found.");

                return self::FAILURE;
            }
            $this->info("Backfilling character assets for project {$projectId} ({$project->title})...");
        } else {
            $this->info('Backfilling character assets for all projects...');
        }

        $created = 0;
        $characters = $query->get();

        foreach ($characters as $character) {
            $before = $character->assets()->count();
            $assetService->syncLegacyAssets($character);
            $after = $character->assets()->count();
            $added = $after - $before;

            if ($added > 0) {
                $created += $added;
                $this->line("  {$character->name} (id {$character->id}): +{$added} assets");
            }
        }

        $this->info("Done. Created {$created} character asset rows.");

        return self::SUCCESS;
    }
}
