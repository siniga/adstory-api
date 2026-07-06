<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_projects', 'shot_generation_status')) {
                $table->string('shot_generation_status')->nullable()->after('scene_generation_finished_at');
            }

            if (! Schema::hasColumn('adstory_projects', 'shot_generation_total')) {
                $table->unsignedInteger('shot_generation_total')->default(0)->after('shot_generation_status');
            }

            if (! Schema::hasColumn('adstory_projects', 'shot_generation_completed')) {
                $table->unsignedInteger('shot_generation_completed')->default(0)->after('shot_generation_total');
            }

            if (! Schema::hasColumn('adstory_projects', 'shot_generation_failed')) {
                $table->unsignedInteger('shot_generation_failed')->default(0)->after('shot_generation_completed');
            }

            if (! Schema::hasColumn('adstory_projects', 'shot_generation_started_at')) {
                $table->timestamp('shot_generation_started_at')->nullable()->after('shot_generation_failed');
            }

            if (! Schema::hasColumn('adstory_projects', 'shot_generation_finished_at')) {
                $table->timestamp('shot_generation_finished_at')->nullable()->after('shot_generation_started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_projects', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('adstory_projects', 'shot_generation_status') ? 'shot_generation_status' : null,
                Schema::hasColumn('adstory_projects', 'shot_generation_total') ? 'shot_generation_total' : null,
                Schema::hasColumn('adstory_projects', 'shot_generation_completed') ? 'shot_generation_completed' : null,
                Schema::hasColumn('adstory_projects', 'shot_generation_failed') ? 'shot_generation_failed' : null,
                Schema::hasColumn('adstory_projects', 'shot_generation_started_at') ? 'shot_generation_started_at' : null,
                Schema::hasColumn('adstory_projects', 'shot_generation_finished_at') ? 'shot_generation_finished_at' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
