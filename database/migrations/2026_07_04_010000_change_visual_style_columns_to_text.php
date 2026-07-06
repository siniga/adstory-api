<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE adstory_scenes MODIFY visual_style TEXT NULL');

        if (Schema::hasColumn('adstory_projects', 'visual_style')) {
            DB::statement('ALTER TABLE adstory_projects MODIFY visual_style TEXT NULL');
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE adstory_scenes MODIFY visual_style VARCHAR(255) NULL');

        if (Schema::hasColumn('adstory_projects', 'visual_style')) {
            DB::statement('ALTER TABLE adstory_projects MODIFY visual_style VARCHAR(255) NULL');
        }
    }
};
