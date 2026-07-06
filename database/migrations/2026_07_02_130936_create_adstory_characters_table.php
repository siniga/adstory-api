<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adstory_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adstory_project_id')
                ->constrained('adstory_projects')
                ->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->longText('description')->nullable();
            $table->longText('personality')->nullable();
            $table->longText('appearance')->nullable();
            $table->longText('wardrobe')->nullable();
            $table->string('age', 100)->nullable();
            $table->string('gender', 100)->nullable();
            $table->string('image_url')->nullable();
            $table->string('image_status', 100)->nullable()->default('pending');
            $table->longText('prompt')->nullable();
            $table->json('references')->nullable();
            $table->integer('order_index')->default(0);
            $table->string('status')->default('draft');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adstory_characters');
    }
};
