<?php

namespace App\Console\Commands;

use App\Jobs\ProcessWebhookEvent;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Console\Command;

class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile';

    protected $description = 'Dispatch due generation checks and recover undelivered inbox jobs';

    public function handle(): int
    {
        Generation::query()->whereIn('status', ['queued', 'processing'])
            ->where('next_sync_at', '<=', now())->orderBy('next_sync_at')->limit(40)
            ->get()->each(fn (Generation $generation) => SyncGeneration::dispatch($generation->id));
        WebhookEvent::query()->whereNull('processed_at')->whereNull('failed_at')
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('created_at')->limit(100)->get()
            ->each(fn (WebhookEvent $event) => ProcessWebhookEvent::dispatch($event->id));

        return self::SUCCESS;
    }
}
