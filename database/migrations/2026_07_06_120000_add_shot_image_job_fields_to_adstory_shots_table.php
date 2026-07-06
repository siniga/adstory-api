<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_shots', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_shots', 'image_progress')) {
                $table->unsignedTinyInteger('image_progress')->default(0)->after('image_status');
            }

            if (! Schema::hasColumn('adstory_shots', 'image_generation_started_at')) {
                $table->timestamp('image_generation_started_at')->nullable()->after('image_progress');
            }

            if (! Schema::hasColumn('adstory_shots', 'image_generation_completed_at')) {
                $table->timestamp('image_generation_completed_at')->nullable()->after('image_generation_started_at');
            }

            if (! Schema::hasColumn('adstory_shots', 'image_retry_count')) {
                $table->unsignedSmallInteger('image_retry_count')->default(0)->after('generation_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_shots', function (Blueprint $table) {
            foreach ([
                'image_progress',
                'image_generation_started_at',
                'image_generation_completed_at',
                'image_retry_count',
            ] as $column) {
                if (Schema::hasColumn('adstory_shots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
