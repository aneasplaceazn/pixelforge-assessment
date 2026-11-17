<?php

namespace App\Jobs;

use App\Actions\ProcessWebhook;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebhookEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public int $uniqueFor = 90;

    public int $backoff = 30;

    public function __construct(public string $eventId) {}

    public function uniqueId(): string
    {
        return $this->eventId;
    }

    public function handle(ProcessWebhook $process): void
    {
        $process->handle($this->eventId);
    }

    public function failed(?\Throwable $exception): void
    {
        WebhookEvent::query()->whereKey($this->eventId)->whereNull('processed_at')
            ->update(['failed_at' => now(), 'next_attempt_at' => null, 'error_code' => 'worker_failed']);
    }
}
