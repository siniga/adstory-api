<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_scenes', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_scenes', 'shot_generation_status')) {
                $table->string('shot_generation_status', 100)->nullable()->after('status');
            }

            if (! Schema::hasColumn('adstory_scenes', 'shot_generation_error')) {
                $table->longText('shot_generation_error')->nullable()->after('shot_generation_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_scenes', function (Blueprint $table) {
            if (Schema::hasColumn('adstory_scenes', 'shot_generation_error')) {
                $table->dropColumn('shot_generation_error');
            }

            if (Schema::hasColumn('adstory_scenes', 'shot_generation_status')) {
                $table->dropColumn('shot_generation_status');
            }
        });
    }
};
