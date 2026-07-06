<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adstory_shot_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adstory_project_id')
                ->constrained('adstory_projects')
                ->cascadeOnDelete();
            $table->foreignId('adstory_scene_id')
                ->nullable()
                ->constrained('adstory_scenes')
                ->cascadeOnDelete();
            $table->foreignId('adstory_shot_id')
                ->constrained('adstory_shots')
                ->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('image_url');
            $table->string('thumbnail_url')->nullable();
            $table->longText('prompt');
            $table->longText('negative_prompt')->nullable();
            $table->string('seed')->nullable();
            $table->string('model')->nullable();
            $table->string('status')->default('completed');
            $table->boolean('is_approved')->default(false);
            $table->unsignedInteger('generation_time_ms')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['adstory_shot_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adstory_shot_images');
    }
};
