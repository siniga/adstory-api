<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adstory_ai_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adstory_project_id')
                ->constrained('adstory_projects')
                ->cascadeOnDelete();
            $table->string('taskable_type')->nullable();
            $table->unsignedBigInteger('taskable_id')->nullable();
            $table->string('type');
            $table->string('status')->default('queued');
            $table->integer('priority')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->longText('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority', 'id']);
            $table->index(['adstory_project_id', 'type', 'status']);
            $table->index(['taskable_type', 'taskable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adstory_ai_tasks');
    }
};
