<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_shots', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_shots', 'selected_character_assets')) {
                $table->json('selected_character_assets')->nullable()->after('meta');
            }

            if (! Schema::hasColumn('adstory_shots', 'selected_environment_assets')) {
                $table->json('selected_environment_assets')->nullable()->after('selected_character_assets');
            }

            if (! Schema::hasColumn('adstory_shots', 'composition_preset')) {
                $table->json('composition_preset')->nullable()->after('selected_environment_assets');
            }

            if (! Schema::hasColumn('adstory_shots', 'cinematography_preset')) {
                $table->json('cinematography_preset')->nullable()->after('composition_preset');
            }

            if (! Schema::hasColumn('adstory_shots', 'lighting_preset')) {
                $table->json('lighting_preset')->nullable()->after('cinematography_preset');
            }

            if (! Schema::hasColumn('adstory_shots', 'storyboard_settings')) {
                $table->json('storyboard_settings')->nullable()->after('lighting_preset');
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_shots', function (Blueprint $table) {
            $columns = [
                'selected_character_assets',
                'selected_environment_assets',
                'composition_preset',
                'cinematography_preset',
                'lighting_preset',
                'storyboard_settings',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('adstory_shots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
