<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adstory_environments', function (Blueprint $table) {
            if (! Schema::hasColumn('adstory_environments', 'appearance')) {
                $table->longText('appearance')->nullable()->after('description');
            }

            if (! Schema::hasColumn('adstory_environments', 'lighting')) {
                $table->string('lighting')->nullable()->after('mood');
            }

            if (! Schema::hasColumn('adstory_environments', 'references')) {
                $table->json('references')->nullable()->after('prompt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('adstory_environments', function (Blueprint $table) {
            if (Schema::hasColumn('adstory_environments', 'appearance')) {
                $table->dropColumn('appearance');
            }

            if (Schema::hasColumn('adstory_environments', 'lighting')) {
                $table->dropColumn('lighting');
            }

            if (Schema::hasColumn('adstory_environments', 'references')) {
                $table->dropColumn('references');
            }
        });
    }
};
