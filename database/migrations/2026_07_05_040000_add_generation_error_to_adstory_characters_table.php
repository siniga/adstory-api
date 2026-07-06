<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_characters', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_characters', 'generation_error')) {
                $table->text('generation_error')->nullable()->after('image_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_characters', function (Blueprint $table) {
            if (Schema::hasColumn('adstory_characters', 'generation_error')) {
                $table->dropColumn('generation_error');
            }
        });
    }
};
