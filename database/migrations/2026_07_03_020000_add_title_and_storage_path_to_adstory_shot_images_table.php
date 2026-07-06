<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_shot_images', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_shot_images', 'title')) {
                $table->string('title')->nullable()->after('version_number');
            }

            if (! Schema::hasColumn('adstory_shot_images', 'storage_path')) {
                $table->string('storage_path')->nullable()->after('image_url');
            }
        });

        // Align nullable columns and default status with the spec without dropping legacy columns.
        Schema::table('adstory_shot_images', function (Blueprint $table) {
            $table->string('image_url')->nullable()->change();
            $table->longText('prompt')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('adstory_shot_images', function (Blueprint $table) {
            if (Schema::hasColumn('adstory_shot_images', 'title')) {
                $table->dropColumn('title');
            }

            if (Schema::hasColumn('adstory_shot_images', 'storage_path')) {
                $table->dropColumn('storage_path');
            }
        });
    }
};
