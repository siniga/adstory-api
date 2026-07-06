<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('adstory_scenes', 'environment')) {
            Schema::table('adstory_scenes', function (Blueprint $table) {
                $table->text('environment')->nullable()->after('location');
            });
        } else {
            Schema::table('adstory_scenes', function (Blueprint $table) {
                $table->text('environment')->nullable()->change();
            });
        }

        DB::table('adstory_scenes')
            ->select(['id', 'meta', 'environment'])
            ->orderBy('id')
            ->chunkById(100, function ($scenes) {
                foreach ($scenes as $scene) {
                    if (! empty($scene->environment)) {
                        continue;
                    }

                    $meta = json_decode($scene->meta ?? '', true);

                    if (! is_array($meta) || empty($meta['environment'])) {
                        continue;
                    }

                    DB::table('adstory_scenes')
                        ->where('id', $scene->id)
                        ->update(['environment' => $meta['environment']]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('adstory_scenes', 'environment')) {
            Schema::table('adstory_scenes', function (Blueprint $table) {
                $table->dropColumn('environment');
            });
        }
    }
};
