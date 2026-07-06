<?php

namespace App\Jobs\Adstory;

use App\Services\Adstory\AdstoryAiTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAdstoryAiTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('adstory-ai');
    }

    public function handle(AdstoryAiTaskService $aiTaskService): void
    {
        $processed = false;

        try {
            $processed = $aiTaskService->processNextTask();
        } catch (Throwable $e) {
            Log::error('Adstory AI task: worker handle exception', [
                'message' => $e->getMessage(),
            ]);
        } finally {
            $remaining = $aiTaskService->countEligibleQueuedTasks();

            Log::info('Adstory AI task: queued tasks remaining', [
                'count' => $remaining,
                'processed' => $processed,
            ]);

            if ($remaining > 0) {
                self::dispatch();

                Log::info('Adstory AI task: worker re-dispatched', [
                    'queued_remaining' => $remaining,
                ]);
            }
        }
    }
}
