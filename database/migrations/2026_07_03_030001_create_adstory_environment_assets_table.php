<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adstory_environment_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adstory_project_id')
                ->constrained('adstory_projects')
                ->cascadeOnDelete();
            $table->foreignId('adstory_environment_id')
                ->constrained('adstory_environments')
                ->cascadeOnDelete();
            $table->string('asset_type')->nullable();
            $table->string('title')->nullable();
            $table->string('image_url')->nullable();
            $table->string('storage_path')->nullable();
            $table->longText('prompt')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status')->default('completed');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adstory_environment_assets');
    }
};
