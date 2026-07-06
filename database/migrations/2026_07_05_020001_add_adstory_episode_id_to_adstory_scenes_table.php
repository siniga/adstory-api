<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_scenes', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_scenes', 'adstory_episode_id')) {
                $table->foreignId('adstory_episode_id')
                    ->nullable()
                    ->after('adstory_project_id')
                    ->constrained('adstory_episodes')
                    ->nullOnDelete();

                $table->index('adstory_episode_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_scenes', function (Blueprint $table) {
            if (Schema::hasColumn('adstory_scenes', 'adstory_episode_id')) {
                $table->dropConstrainedForeignId('adstory_episode_id');
            }
        });
    }
};
