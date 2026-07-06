<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_projects', 'story')) {
                $table->longText('story')->nullable()->after('title');
            }

            if (! Schema::hasColumn('adstory_projects', 'script')) {
                $table->longText('script')->nullable()->after('story');
            }

            if (! Schema::hasColumn('adstory_projects', 'screenplay')) {
                $table->longText('screenplay')->nullable()->after('script');
            }

            if (! Schema::hasColumn('adstory_projects', 'visual_style')) {
                $table->string('visual_style', 255)->nullable()->after('screenplay');
            }

            if (! Schema::hasColumn('adstory_projects', 'current_step')) {
                $table->string('current_step', 100)->nullable()->default('story')->after('visual_style');
            }

            if (! Schema::hasColumn('adstory_projects', 'status')) {
                $table->string('status', 100)->nullable()->default('draft')->after('current_step');
            }

            if (! Schema::hasColumn('adstory_projects', 'meta')) {
                $table->json('meta')->nullable()->after('status');
            }
        });

        if (Schema::hasColumn('adstory_projects', 'story_text')) {
            DB::table('adstory_projects')
                ->whereNotNull('story_text')
                ->whereNull('story')
                ->update([
                    'story' => DB::raw('story_text'),
                ]);
        }

        if (Schema::hasColumn('adstory_projects', 'style')) {
            DB::table('adstory_projects')
                ->whereNotNull('style')
                ->whereNull('visual_style')
                ->update([
                    'visual_style' => DB::raw('style'),
                ]);
        }

        if (Schema::hasColumn('adstory_projects', 'story_status')) {
            DB::table('adstory_projects')
                ->whereNotNull('story_status')
                ->whereNull('status')
                ->update([
                    'status' => DB::raw('story_status'),
                ]);
        }

        Schema::table('adstory_projects', function (Blueprint $table) {
            $columnsToDrop = array_filter([
                Schema::hasColumn('adstory_projects', 'story_text') ? 'story_text' : null,
                Schema::hasColumn('adstory_projects', 'style') ? 'style' : null,
                Schema::hasColumn('adstory_projects', 'story_status') ? 'story_status' : null,
            ]);

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_projects', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_projects', 'story_text')) {
                $table->longText('story_text')->nullable();
            }

            if (! Schema::hasColumn('adstory_projects', 'style')) {
                $table->string('style', 100)->nullable();
            }

            if (! Schema::hasColumn('adstory_projects', 'story_status')) {
                $table->string('story_status', 50)->nullable();
            }
        });

        if (Schema::hasColumn('adstory_projects', 'story')) {
            DB::table('adstory_projects')
                ->whereNotNull('story')
                ->update([
                    'story_text' => DB::raw('story'),
                ]);
        }

        if (Schema::hasColumn('adstory_projects', 'visual_style')) {
            DB::table('adstory_projects')
                ->whereNotNull('visual_style')
                ->update([
                    'style' => DB::raw('visual_style'),
                ]);
        }

        if (Schema::hasColumn('adstory_projects', 'status')) {
            DB::table('adstory_projects')
                ->whereNotNull('status')
                ->update([
                    'story_status' => DB::raw('status'),
                ]);
        }

        Schema::table('adstory_projects', function (Blueprint $table) {
            $columnsToDrop = array_filter([
                Schema::hasColumn('adstory_projects', 'story') ? 'story' : null,
                Schema::hasColumn('adstory_projects', 'script') ? 'script' : null,
                Schema::hasColumn('adstory_projects', 'screenplay') ? 'screenplay' : null,
                Schema::hasColumn('adstory_projects', 'visual_style') ? 'visual_style' : null,
                Schema::hasColumn('adstory_projects', 'current_step') ? 'current_step' : null,
                Schema::hasColumn('adstory_projects', 'status') ? 'status' : null,
                Schema::hasColumn('adstory_projects', 'meta') ? 'meta' : null,
            ]);

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
