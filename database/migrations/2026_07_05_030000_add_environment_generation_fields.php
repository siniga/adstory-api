<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_environments', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_environments', 'location_type')) {
                $table->string('location_type')->nullable()->after('type');
            }

            if (! Schema::hasColumn('adstory_environments', 'visual_style')) {
                $table->string('visual_style')->nullable()->after('mood');
            }

            if (! Schema::hasColumn('adstory_environments', 'generation_error')) {
                $table->longText('generation_error')->nullable()->after('prompt');
            }
        });

        Schema::table('adstory_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_projects', 'environment_generation_status')) {
                $table->string('environment_generation_status')->nullable()->after('character_generation_finished_at');
            }

            if (! Schema::hasColumn('adstory_projects', 'environment_generation_total')) {
                $table->unsignedInteger('environment_generation_total')->default(0)->after('environment_generation_status');
            }

            if (! Schema::hasColumn('adstory_projects', 'environment_generation_completed')) {
                $table->unsignedInteger('environment_generation_completed')->default(0)->after('environment_generation_total');
            }

            if (! Schema::hasColumn('adstory_projects', 'environment_generation_failed')) {
                $table->unsignedInteger('environment_generation_failed')->default(0)->after('environment_generation_completed');
            }

            if (! Schema::hasColumn('adstory_projects', 'environment_generation_started_at')) {
                $table->timestamp('environment_generation_started_at')->nullable()->after('environment_generation_failed');
            }

            if (! Schema::hasColumn('adstory_projects', 'environment_generation_finished_at')) {
                $table->timestamp('environment_generation_finished_at')->nullable()->after('environment_generation_started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_environments', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('adstory_environments', 'location_type') ? 'location_type' : null,
                Schema::hasColumn('adstory_environments', 'visual_style') ? 'visual_style' : null,
                Schema::hasColumn('adstory_environments', 'generation_error') ? 'generation_error' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('adstory_projects', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('adstory_projects', 'environment_generation_status') ? 'environment_generation_status' : null,
                Schema::hasColumn('adstory_projects', 'environment_generation_total') ? 'environment_generation_total' : null,
                Schema::hasColumn('adstory_projects', 'environment_generation_completed') ? 'environment_generation_completed' : null,
                Schema::hasColumn('adstory_projects', 'environment_generation_failed') ? 'environment_generation_failed' : null,
                Schema::hasColumn('adstory_projects', 'environment_generation_started_at') ? 'environment_generation_started_at' : null,
                Schema::hasColumn('adstory_projects', 'environment_generation_finished_at') ? 'environment_generation_finished_at' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
