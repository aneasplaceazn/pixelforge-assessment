<?php

use App\Actions\ApplyGeneration;
use App\Actions\ProcessWebhook;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
    $this->freezeTime();
});

test('signed events are durably deduplicated before queueing', function () {
    $generation = Generation::factory()->create();
    Queue::fake([ProcessWebhookEvent::class]);
    $event = generationEvent($generation, 'succeeded');

    sendPixelforgeEvent($event)->assertNoContent();
    sendPixelforgeEvent($event)->assertNoContent();

    $this->assertDatabaseCount('webhook_events', 1);
    Queue::assertPushed(ProcessWebhookEvent::class, fn ($job) => $job->eventId === 'evt_test');
});

test('missing malformed expired future and incorrect signatures are rejected without writes', function (string $signature) {
    Queue::fake([ProcessWebhookEvent::class]);

    sendPixelforgeEvent(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping'], $signature)->assertBadRequest();

    $this->assertDatabaseCount('webhook_events', 0);
    Queue::assertNothingPushed();
})->with(['', 'garbage', 't=1,v1='.str_repeat('a', 64), 't=999999999999,v1='.str_repeat('a', 64), 't=not-a-number,v1=x', 't=123,v1=x,v1=y']);

test('a valid signed ping succeeds', function () {
    sendPixelforgeEvent(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping'])->assertNoContent();

    expect(WebhookEvent::findOrFail('evt_ping')->processed_at)->not->toBeNull();
});

test('webhooks can settle before creation returns and stale responses cannot regress them', function () {
    $generation = Generation::factory()->create();
    sendPixelforgeEvent(generationEvent($generation, 'succeeded'))->assertNoContent();

    app(ApplyGeneration::class)->handle(pixelforgeSnapshot($generation, 'queued'), $generation->id);
    sendPixelforgeEvent(generationEvent($generation, 'processing', 'evt_processing'))->assertNoContent();
    sendPixelforgeEvent(generationEvent($generation, 'succeeded', 'evt_repeated'))->assertNoContent();
    app(ProcessWebhook::class)->handle('evt_test');

    expect($generation->fresh()->status)->toBe('succeeded');
    expect($generation->fresh()->user_charge)->toBe('0.014031250000000000');
    $this->assertDatabaseCount('generations', 1);
    $this->assertDatabaseCount('webhook_events', 3);
});

test('failed states remain free through repeated and contradictory events', function () {
    $generation = Generation::factory()->create();
    sendPixelforgeEvent(generationEvent($generation, 'failed'))->assertNoContent();
    sendPixelforgeEvent(generationEvent($generation, 'failed', 'evt_again'))->assertNoContent();
    sendPixelforgeEvent(generationEvent($generation, 'succeeded', 'evt_conflict'))->assertNoContent();

    expect($generation->fresh()->status)->toBe('failed');
    expect($generation->fresh()->user_charge)->toBe('0.000000000000000000');
});

test('processing cannot regress to queued', function () {
    $generation = Generation::factory()->create();
    app(ApplyGeneration::class)->handle(pixelforgeSnapshot($generation, 'processing'));
    app(ApplyGeneration::class)->handle(pixelforgeSnapshot($generation, 'queued'));

    expect($generation->fresh()->status)->toBe('processing');
});

test('otherwise valid signatures outside the timestamp window are rejected', function (int $offset) {
    $body = ['id' => 'evt_old', 'object' => 'event', 'type' => 'ping'];
    $raw = json_encode($body, JSON_UNESCAPED_SLASHES);
    $timestamp = now()->getTimestamp() + $offset;
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, 'whsec_test');

    sendPixelforgeEvent($body, $signature)->assertBadRequest();
    $this->assertDatabaseCount('webhook_events', 0);
})->with([-301, 301]);

test('body tampering invalidates a fresh signature', function () {
    $timestamp = now()->getTimestamp();
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.{}', 'whsec_test');

    sendPixelforgeEvent(['id' => 'evt_changed', 'object' => 'event', 'type' => 'ping'], $signature)->assertBadRequest();
    $this->assertDatabaseCount('webhook_events', 0);
});

test('unconfigured webhook secrets never accept an event', function () {
    config(['services.pixelforge.webhook_secret' => null]);

    sendPixelforgeEvent(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping'])->assertServiceUnavailable();
    $this->assertDatabaseCount('webhook_events', 0);
});

test('mismatched provider identity cannot modify another generation', function () {
    $generation = Generation::factory()->create(['provider_id' => 'gen_original']);
    $snapshot = pixelforgeSnapshot($generation);
    $snapshot['id'] = 'gen_other';

    expect(fn () => app(ApplyGeneration::class)->handle($snapshot))->toThrow(InvalidArgumentException::class);
    expect($generation->fresh()->user_charge)->toBeNull();
});
