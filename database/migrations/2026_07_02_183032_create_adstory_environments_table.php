<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('adstory_environments')) {
            return;
        }

        Schema::create('adstory_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adstory_project_id')
                ->constrained('adstory_projects')
                ->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->string('time_of_day', 100)->nullable();
            $table->longText('description')->nullable();
            $table->string('mood')->nullable();
            $table->string('image_url')->nullable();
            $table->string('image_status', 100)->nullable()->default('pending');
            $table->longText('prompt')->nullable();
            $table->integer('order_index')->default(0);
            $table->string('status')->default('draft');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adstory_environments');
    }
};
