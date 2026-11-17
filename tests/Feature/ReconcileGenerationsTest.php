<?php

use App\Jobs\ProcessWebhookEvent;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

test('the scheduler recovers lost dispatches while respecting backoff and terminal states', function () {
    $due = Generation::factory()->create();
    Generation::factory()->create(['next_sync_at' => now()->addHour()]);
    Generation::factory()->create(['status' => 'failed', 'provider_cost' => '0', 'user_charge' => '0']);
    Generation::factory()->create(['next_sync_at' => null]);
    WebhookEvent::create(['id' => 'evt_recover', 'type' => 'ping', 'payload' => ['type' => 'ping']]);
    Queue::fake([SyncGeneration::class, ProcessWebhookEvent::class]);

    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    Queue::assertPushed(SyncGeneration::class, fn ($job) => $job->generationId === $due->id);
    Queue::assertPushed(SyncGeneration::class, 1);
    Queue::assertPushed(ProcessWebhookEvent::class, fn ($job) => $job->eventId === 'evt_recover');
});
