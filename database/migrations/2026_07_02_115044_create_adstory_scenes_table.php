<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adstory_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adstory_project_id')
                ->constrained('adstory_projects')
                ->cascadeOnDelete();
            $table->integer('scene_number')->nullable();
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->string('location')->nullable();
            $table->string('time_of_day', 100)->nullable();
            $table->longText('description')->nullable();
            $table->longText('screenplay_excerpt')->nullable();
            $table->string('mood')->nullable();
            $table->string('visual_style', 255)->nullable();
            $table->integer('order_index')->default(0);
            $table->string('status')->default('draft');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adstory_scenes');
    }
};
