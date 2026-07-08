<?php

namespace App\Jobs\Adstory;

use App\Services\Adstory\AdstoryShotImageJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateShotImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout;

    public function __construct(
        public int $shotId,
        public ?string $customPrompt = null,
        public bool $force = false,
    ) {
        $this->timeout = AdstoryShotImageJobService::JOB_TIMEOUT_SECONDS;
        $this->onQueue(AdstoryShotImageJobService::QUEUE_NAME);
    }

    public function handle(AdstoryShotImageJobService $shotImageJobService): void
    {
        $shotImageJobService->executeShotImageGeneration(
            shotId: $this->shotId,
            customPrompt: $this->customPrompt,
            force: $this->force,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $message = $exception?->getMessage() ?? 'Shot image job failed or timed out.';

        Log::error('Failed Shot '.$this->shotId, [
            'shot_id' => $this->shotId,
            'message' => $message,
        ]);

        app(AdstoryShotImageJobService::class)->markShotFailedFromJob(
            shotId: $this->shotId,
            error: $message,
        );
    }
}
