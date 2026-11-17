<?php

namespace App\Actions;

use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ProcessWebhook
{
    public function __construct(private ApplyGeneration $apply) {}

    public function handle(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $event = WebhookEvent::query()->lockForUpdate()->findOrFail($id);
            if ($event->processed_at !== null || $event->failed_at !== null || $event->next_attempt_at?->isFuture()) {
                return;
            }
            $event->increment('attempts');
            try {
                DB::transaction(function () use ($event): void {
                    if ($event->type !== 'ping') {
                        $snapshot = $event->payload['data']['object'] ?? [];
                        if ('generation.'.($snapshot['status'] ?? '') !== $event->type) {
                            throw new InvalidArgumentException('Webhook status mismatch.');
                        }
                        $this->apply->handle($snapshot);
                    }
                });
                $event->update(['processed_at' => now(), 'next_attempt_at' => null, 'error_code' => null, 'payload' => []]);
            } catch (Throwable $exception) {
                $failed = $exception instanceof InvalidArgumentException || $event->attempts >= 5;
                $event->update([
                    'failed_at' => $failed ? now() : null,
                    'next_attempt_at' => $failed ? null : now()->addSeconds(10 * (2 ** $event->attempts)),
                    'error_code' => $exception instanceof InvalidArgumentException ? 'invalid_snapshot' : 'processing_failed',
                ]);
                Log::warning('PixelForge webhook processing deferred or failed.', ['event_id' => $event->id, 'attempt' => $event->attempts, 'failed' => $failed, 'error_code' => $event->error_code]);
            }
        }, 3);
    }
}
