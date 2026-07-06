<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_scenes', function (Blueprint $table) {
            $table->longText('generation_error')->nullable()->after('status');
            $table->timestamp('generated_at')->nullable()->after('generation_error');
        });

        Schema::table('adstory_projects', function (Blueprint $table) {
            $table->string('scene_generation_status')->nullable()->after('status');
            $table->unsignedInteger('scene_generation_total')->default(0)->after('scene_generation_status');
            $table->unsignedInteger('scene_generation_completed')->default(0)->after('scene_generation_total');
            $table->unsignedInteger('scene_generation_failed')->default(0)->after('scene_generation_completed');
            $table->timestamp('scene_generation_started_at')->nullable()->after('scene_generation_failed');
            $table->timestamp('scene_generation_finished_at')->nullable()->after('scene_generation_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('adstory_scenes', function (Blueprint $table) {
            $table->dropColumn(['generation_error', 'generated_at']);
        });

        Schema::table('adstory_projects', function (Blueprint $table) {
            $table->dropColumn([
                'scene_generation_status',
                'scene_generation_total',
                'scene_generation_completed',
                'scene_generation_failed',
                'scene_generation_started_at',
                'scene_generation_finished_at',
            ]);
        });
    }
};
