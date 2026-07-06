<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adstory_episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adstory_project_id')->constrained('adstory_projects')->cascadeOnDelete();
            $table->unsignedInteger('episode_number');
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedInteger('estimated_scene_count')->default(5);
            $table->unsignedInteger('start_scene_number')->nullable();
            $table->unsignedInteger('end_scene_number')->nullable();
            $table->string('status')->default('draft');
            $table->string('scene_generation_status')->nullable();
            $table->longText('scene_generation_error')->nullable();
            $table->string('shot_generation_status')->nullable();
            $table->longText('shot_generation_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('adstory_project_id');
            $table->index('episode_number');
            $table->index('status');
            $table->unique(['adstory_project_id', 'episode_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adstory_episodes');
    }
};
