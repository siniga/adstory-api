<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_projects', 'character_generation_status')) {
                $table->string('character_generation_status')->nullable()->after('shot_generation_finished_at');
            }

            if (! Schema::hasColumn('adstory_projects', 'character_generation_total')) {
                $table->unsignedInteger('character_generation_total')->default(0)->after('character_generation_status');
            }

            if (! Schema::hasColumn('adstory_projects', 'character_generation_completed')) {
                $table->unsignedInteger('character_generation_completed')->default(0)->after('character_generation_total');
            }

            if (! Schema::hasColumn('adstory_projects', 'character_generation_failed')) {
                $table->unsignedInteger('character_generation_failed')->default(0)->after('character_generation_completed');
            }

            if (! Schema::hasColumn('adstory_projects', 'character_generation_started_at')) {
                $table->timestamp('character_generation_started_at')->nullable()->after('character_generation_failed');
            }

            if (! Schema::hasColumn('adstory_projects', 'character_generation_finished_at')) {
                $table->timestamp('character_generation_finished_at')->nullable()->after('character_generation_started_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_projects', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('adstory_projects', 'character_generation_status') ? 'character_generation_status' : null,
                Schema::hasColumn('adstory_projects', 'character_generation_total') ? 'character_generation_total' : null,
                Schema::hasColumn('adstory_projects', 'character_generation_completed') ? 'character_generation_completed' : null,
                Schema::hasColumn('adstory_projects', 'character_generation_failed') ? 'character_generation_failed' : null,
                Schema::hasColumn('adstory_projects', 'character_generation_started_at') ? 'character_generation_started_at' : null,
                Schema::hasColumn('adstory_projects', 'character_generation_finished_at') ? 'character_generation_finished_at' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
