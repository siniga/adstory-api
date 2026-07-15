<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_projects', 'cover_image_url')) {
                $table->string('cover_image_url', 2048)->nullable()->after('visual_style');
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_projects', function (Blueprint $table) {
            if (Schema::hasColumn('adstory_projects', 'cover_image_url')) {
                $table->dropColumn('cover_image_url');
            }
        });
    }
};
