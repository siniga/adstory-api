<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_shots', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_shots', 'image_prompt')) {
                $table->longText('image_prompt')->nullable()->after('prompt');
            }

            if (! Schema::hasColumn('adstory_shots', 'generation_error')) {
                $table->longText('generation_error')->nullable()->after('image_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_shots', function (Blueprint $table) {
            if (Schema::hasColumn('adstory_shots', 'image_prompt')) {
                $table->dropColumn('image_prompt');
            }

            if (Schema::hasColumn('adstory_shots', 'generation_error')) {
                $table->dropColumn('generation_error');
            }
        });
    }
};
