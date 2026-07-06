<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('adstory_scenes', 'mood')) {
            DB::statement('ALTER TABLE adstory_scenes MODIFY mood TEXT NULL');
        }

        if (Schema::hasColumn('adstory_scenes', 'environment')) {
            DB::statement('ALTER TABLE adstory_scenes MODIFY environment LONGTEXT NULL');
        }

        if (Schema::hasColumn('adstory_scenes', 'visual_style')) {
            DB::statement('ALTER TABLE adstory_scenes MODIFY visual_style TEXT NULL');
        }

        if (Schema::hasColumn('adstory_scenes', 'description')) {
            DB::statement('ALTER TABLE adstory_scenes MODIFY description LONGTEXT NULL');
        }

        if (Schema::hasColumn('adstory_scenes', 'screenplay_excerpt')) {
            DB::statement('ALTER TABLE adstory_scenes MODIFY screenplay_excerpt LONGTEXT NULL');
        }

        if (Schema::hasColumn('adstory_scenes', 'generation_error')) {
            DB::statement('ALTER TABLE adstory_scenes MODIFY generation_error LONGTEXT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('adstory_scenes', 'mood')) {
            DB::statement('ALTER TABLE adstory_scenes MODIFY mood VARCHAR(255) NULL');
        }

        if (Schema::hasColumn('adstory_scenes', 'environment')) {
            DB::statement('ALTER TABLE adstory_scenes MODIFY environment TEXT NULL');
        }

        if (Schema::hasColumn('adstory_scenes', 'visual_style')) {
            DB::statement('ALTER TABLE adstory_scenes MODIFY visual_style VARCHAR(255) NULL');
        }
    }
};
