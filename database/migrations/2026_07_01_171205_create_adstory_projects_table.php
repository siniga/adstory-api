<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adstory_projects', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->longText('story_text')->nullable();
            $table->string('style', 100)->nullable();
            $table->string('story_status', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adstory_projects');
    }
};
