<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adstory_shots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adstory_project_id')
                ->constrained('adstory_projects')
                ->cascadeOnDelete();
            $table->foreignId('adstory_scene_id')
                ->nullable()
                ->constrained('adstory_scenes')
                ->cascadeOnDelete();
            $table->string('shot_number')->nullable();
            $table->string('title')->nullable();
            $table->longText('description')->nullable();
            $table->longText('action')->nullable();
            $table->longText('dialogue')->nullable();
            $table->string('shot_size')->nullable();
            $table->string('camera_angle')->nullable();
            $table->string('camera_movement')->nullable();
            $table->string('composition')->nullable();
            $table->string('lens')->nullable();
            $table->string('lighting')->nullable();
            $table->string('environment')->nullable();
            $table->json('characters')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->longText('prompt')->nullable();
            $table->string('image_url')->nullable();
            $table->string('image_status', 100)->nullable()->default('pending');
            $table->integer('order_index')->default(0);
            $table->string('status')->default('draft');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adstory_shots');
    }
};
