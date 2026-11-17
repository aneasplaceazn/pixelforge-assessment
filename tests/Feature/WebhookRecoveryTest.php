<?php

use App\Actions\ApplyGeneration;
use App\Actions\ProcessWebhook;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

test('lost webhook dispatches are recovered even after a day', function () {
    WebhookEvent::create(['id' => 'evt_old', 'type' => 'ping', 'payload' => ['type' => 'ping'], 'created_at' => now()->subDays(2)]);
    Queue::fake([ProcessWebhookEvent::class]);

    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    Queue::assertPushed(ProcessWebhookEvent::class, fn ($job) => $job->eventId === 'evt_old');
});

test('a poison message is quarantined and a backlog of valid old events drains', function () {
    WebhookEvent::create(['id' => 'evt_poison', 'type' => 'generation.succeeded', 'payload' => ['data' => ['object' => ['status' => 'failed']]], 'created_at' => now()->subDays(3)]);
    for ($index = 0; $index < 125; $index++) {
        WebhookEvent::create(['id' => 'evt_backlog_'.$index, 'type' => 'ping', 'payload' => ['type' => 'ping'], 'created_at' => now()->subDays(2)]);
    }

    $this->artisan('pixelforge:reconcile')->assertSuccessful();
    $this->artisan('pixelforge:reconcile')->assertSuccessful();
    $this->artisan('pixelforge:reconcile')->assertSuccessful();

    expect(WebhookEvent::whereNotNull('processed_at')->count())->toBe(125);
    $poison = WebhookEvent::findOrFail('evt_poison');
    expect($poison->failed_at)->not->toBeNull();
    expect($poison->processed_at)->toBeNull();
    expect($poison->attempts)->toBe(1);
    $this->artisan('pixelforge:webhooks')->expectsOutputToContain('invalid_snapshot')->assertFailed();
});

test('transient processing failures back off and stop after five persisted attempts', function () {
    $this->freezeTime();
    $generation = Generation::factory()->create();
    WebhookEvent::create(['id' => 'evt_transient', 'type' => 'generation.succeeded', 'payload' => ['data' => ['object' => pixelforgeSnapshot($generation)]]]);
    $this->mock(ApplyGeneration::class)->shouldReceive('handle')->times(5)->andThrow(new RuntimeException('Synthetic transient failure'));

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        app(ProcessWebhook::class)->handle('evt_transient');
        app(ProcessWebhook::class)->handle('evt_transient');
        expect(WebhookEvent::findOrFail('evt_transient')->attempts)->toBe($attempt);
        $this->travel(10 * (2 ** $attempt))->seconds();
    }
    $event = WebhookEvent::findOrFail('evt_transient');
    expect($event->processed_at)->toBeNull();
    expect($event->failed_at)->not->toBeNull();
    expect($event->next_attempt_at)->toBeNull();
    expect($generation->fresh()->user_charge)->toBeNull();
});

test('operator recovery is explicit and never reprocesses completed events', function () {
    $event = WebhookEvent::create(['id' => 'evt_recover_failed', 'type' => 'ping', 'payload' => ['type' => 'ping'], 'failed_at' => now(), 'error_code' => 'worker_failed', 'attempts' => 3]);

    $this->artisan('pixelforge:webhooks --retry')->assertFailed();
    $this->artisan('pixelforge:webhooks evt_recover_failed --retry')->assertSuccessful();
    expect($event->fresh()->processed_at)->not->toBeNull();
    expect($event->fresh()->failed_at)->toBeNull();
    expect($event->fresh()->attempts)->toBe(1);
    $this->artisan('pixelforge:webhooks evt_recover_failed --retry')->assertFailed();
});

test('exhausted worker failures remain observable without overwriting completed events', function () {
    $event = WebhookEvent::create(['id' => 'evt_worker', 'type' => 'ping', 'payload' => ['type' => 'ping']]);
    $job = new ProcessWebhookEvent($event->id);
    $job->failed(new RuntimeException('Worker stopped'));
    expect($event->fresh()->error_code)->toBe('worker_failed');
    expect($event->fresh()->processed_at)->toBeNull();
    $this->artisan('pixelforge:webhooks evt_worker --retry')->assertSuccessful();
    $job->failed(new RuntimeException('Stale failure callback'));
    expect($event->fresh()->failed_at)->toBeNull();
});
