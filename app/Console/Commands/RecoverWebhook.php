<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverWebhook extends Command
{
    protected $signature = 'pixelforge:webhooks {event_id?} {--retry : Retry one failed event after investigating its cause}';

    protected $description = 'List failed inbox events without exposing their payloads, or retry one explicitly';

    public function handle(): int
    {
        if (! $this->option('retry')) {
            $events = WebhookEvent::query()->whereNotNull('failed_at')->orderBy('failed_at')->limit(50)->get();
            $this->table(['Event', 'Type', 'Attempts', 'Error'], $events->map(fn (WebhookEvent $event): array => [$event->id, $event->type, $event->attempts, $event->error_code]));

            return $events->isEmpty() ? self::SUCCESS : self::FAILURE;
        }
        $id = $this->argument('event_id');
        if (! is_string($id) || $id === '') {
            $this->error('Specify one failed event ID to retry.');

            return self::FAILURE;
        }
        $ready = DB::transaction(function () use ($id): bool {
            $event = WebhookEvent::query()->lockForUpdate()->find($id);
            if ($event === null || $event->processed_at !== null || $event->failed_at === null) {
                return false;
            }
            $event->update(['failed_at' => null, 'error_code' => null, 'attempts' => 0, 'next_attempt_at' => now()]);

            return true;
        });
        if (! $ready) {
            $this->error('That event is not awaiting operator recovery.');

            return self::FAILURE;
        }
        ProcessWebhookEvent::dispatch($id);
        $this->info('The saved event is scheduled for another bounded processing attempt.');

        return self::SUCCESS;
    }
}
