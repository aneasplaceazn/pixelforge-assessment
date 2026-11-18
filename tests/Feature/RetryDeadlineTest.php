<?php

use App\Actions\ApplyGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'test-key', 'services.pixelforge.webhook_secret' => 'whsec_test']);
    Http::preventStrayRequests();
});

test('provider cooldowns survive second precision without allowing early requests', function (string $fraction, string $header, string $required, string $rounded) {
    $this->travelTo('2026-11-18 01:00:00.'.$fraction.' UTC');
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => $header])]);
    $job = new SyncGeneration($generation->id);
    $job->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->retry_not_before->format('H:i:s.u'))->toBe($rounded);
    expect($generation->fresh()->next_sync_at->format('H:i:s.u'))->toBe($rounded);
    $this->travelTo('2026-11-18 '.$required.' UTC');
    $this->travel(-1)->microseconds();
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Http::assertSentCount(1);
    $this->travelTo('2026-11-18 '.$rounded.' UTC');
    $this->travel(-1)->microseconds();
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Http::assertSentCount(1);
    $this->travel(1)->microseconds();
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => ! $request->hasHeader('Idempotency-Key', $generation->request_key));
    expect($generation->fresh()->provider_id)->toBeNull();
    expect($generation->fresh()->user_charge)->toBeNull();
})->with([
    'exact second stays exact' => ['000000', '2', '01:00:10.000000', '01:00:10.000000'],
    'one microsecond rounds upward' => ['000001', '2', '01:00:10.000001', '01:00:11.000000'],
    'half second exponential delay' => ['500000', '2', '01:00:10.500000', '01:00:11.000000'],
    'near next second exponential delay' => ['999999', '2', '01:00:10.999999', '01:00:11.000000'],
    '120 second provider delay' => ['500000', '120', '01:02:00.500000', '01:02:01.000000'],
    '300 second provider delay' => ['500000', '300', '01:05:00.500000', '01:05:01.000000'],
    'near next second provider delay' => ['999999', '300', '01:05:00.999999', '01:05:01.000000'],
]);

test('a fractional provider delay cannot send at the previously truncated deadline', function () {
    $this->travelTo('2026-11-18 01:00:00.500000 UTC');
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '300'])]);
    $job = new SyncGeneration($generation->id);
    $job->handle(app(Client::class), app(ApplyGeneration::class));

    $this->travelTo('2026-11-18 01:05:00.000000 UTC');
    $job->handle(app(Client::class), app(ApplyGeneration::class));

    Http::assertSentCount(1);
});

test('a later cooldown recorded while a request is in flight is never shortened', function () {
    $this->travelTo('2026-11-18 01:00:00.500000 UTC');
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => function () use ($generation) {
        $generation->update(['retry_not_before' => '2026-11-18 01:10:01 UTC']);

        return Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2']);
    }]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->retry_not_before->format('H:i:s.u'))->toBe('01:10:01.000000');
    expect($generation->fresh()->next_sync_at->format('H:i:s.u'))->toBe('01:10:01.000000');
    Http::assertSentCount(1);
});

test('fractional exponential deadlines remain bounded after five rejections', function () {
    $this->travelTo('2026-11-18 01:00:00.500000 UTC');
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2'])]);
    $job = new SyncGeneration($generation->id);
    $deadlines = ['01:00:11', '01:00:32', '01:01:13', '01:02:34', '01:05:15'];

    foreach ($deadlines as $index => $deadline) {
        $job->handle(app(Client::class), app(ApplyGeneration::class));
        expect($generation->fresh()->retry_not_before->format('H:i:s'))->toBe($deadline);
        Http::assertSentCount($index + 1);
        $this->travelTo('2026-11-18 '.$deadline.'.000000 UTC');
        $this->travel(-1)->microseconds();
        $job->handle(app(Client::class), app(ApplyGeneration::class));
        Http::assertSentCount($index + 1);
        $this->travelTo('2026-11-18 '.$deadline.'.500000 UTC');
    }
    $job->handle(app(Client::class), app(ApplyGeneration::class));

    Http::assertSentCount(5);
    expect($generation->fresh()->next_sync_at)->toBeNull();
    expect($generation->fresh()->sync_attempts)->toBe(5);
    expect($generation->fresh()->user_charge)->toBeNull();
    Http::assertNotSent(fn ($request) => ! $request->hasHeader('Idempotency-Key', $generation->request_key));
});

test('manual resume cannot bypass a rounded cooldown after exhaustion', function (string $entry) {
    $this->travelTo('2026-11-18 01:00:00.500000 UTC');
    $generation = Generation::factory()->create(['sync_attempts' => 4]);
    Http::fake(['*/v1/generations' => Http::sequence()
        ->push(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '300'])
        ->push(pixelforgeSnapshot($generation, 'queued'), 202)]);
    $job = new SyncGeneration($generation->id);
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Queue::fake([SyncGeneration::class]);
    if ($entry === 'http') {
        $this->actingAs($generation->user)->post(route('generations.retry', $generation))->assertRedirect();
    } else {
        $this->artisan('pixelforge:resume', ['generation_id' => $generation->id])->assertSuccessful();
    }
    Queue::assertPushed(SyncGeneration::class, 1);

    expect($generation->fresh()->next_sync_at->format('H:i:s.u'))->toBe('01:05:01.000000');
    $this->travelTo('2026-11-18 01:05:00.999999 UTC');
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Http::assertSentCount(1);
    $this->travelTo('2026-11-18 01:05:01 UTC');
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => ! $request->hasHeader('Idempotency-Key', $generation->request_key));
    expect($generation->fresh()->provider_id)->not->toBeNull();
})->with(['http', 'command']);

test('delayed signed processing webhooks preserve a rounded retrieval cooldown', function () {
    $this->travelTo('2026-11-18 01:00:00.500000 UTC');
    $generation = Generation::factory()->create(['provider_id' => 'gen_fractional', 'status' => 'processing']);
    Http::fake(['*/v1/generations/gen_fractional' => Http::sequence()
        ->push(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '120'])
        ->push(pixelforgeSnapshot($generation))]);
    $job = new SyncGeneration($generation->id);
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    $this->travelTo('2026-11-18 01:00:10.500000 UTC');
    sendPixelforgeEvent(generationEvent($generation, 'processing', 'evt_fractional'))->assertNoContent();

    expect($generation->fresh()->next_sync_at->format('H:i:s.u'))->toBe('01:02:01.000000');
    expect($generation->fresh()->retry_not_before->format('H:i:s.u'))->toBe('01:02:01.000000');
    $this->travelTo('2026-11-18 01:02:00.999999 UTC');
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Http::assertSentCount(1);
    $this->travelTo('2026-11-18 01:02:01 UTC');
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Http::assertSentCount(2);
    expect($generation->fresh()->status)->toBe('succeeded');
    expect($generation->fresh()->user_charge)->toBe('0.014031250000000000');
});

test('scheduled recovery and stale dispatched jobs cannot bypass rounded deadlines', function () {
    $this->travelTo('2026-11-18 01:00:00.500000 UTC');
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '120'])]);
    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));
    $generation->refresh()->update(['next_sync_at' => now()]);

    $this->artisan('pixelforge:reconcile')->assertSuccessful();
    Http::assertSentCount(1);
    expect($generation->fresh()->next_sync_at->format('H:i:s.u'))->toBe('01:02:01.000000');
    $this->travelTo('2026-11-18 01:02:00.999999 UTC');
    $this->artisan('pixelforge:reconcile')->assertSuccessful();
    Http::assertSentCount(1);
    $this->travelTo('2026-11-18 01:02:01 UTC');
    $this->artisan('pixelforge:reconcile')->assertSuccessful();
    Http::assertSentCount(2);
    expect($generation->fresh()->user_charge)->toBeNull();
});

test('manual resume stays immediately runnable when no future cooldown exists', function (string $entry, ?string $previous) {
    $this->travelTo('2026-11-18 01:00:00.500000 UTC');
    $generation = Generation::factory()->create(['next_sync_at' => null, 'sync_attempts' => 5, 'retry_not_before' => $previous]);
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'content_policy_violation']], 400)]);

    if ($entry === 'http') {
        $this->actingAs($generation->user)->post(route('generations.retry', $generation))->assertRedirect();
    } else {
        $this->artisan('pixelforge:resume', ['generation_id' => $generation->id])->assertSuccessful();
    }

    Http::assertSentCount(1);
    expect($generation->fresh()->status)->toBe('failed');
    expect($generation->fresh()->user_charge)->toBe('0.000000000000000000');
    expect($generation->fresh()->request_key)->toBe($generation->request_key);
})->with([
    'http without cooldown' => ['http', null],
    'command without cooldown' => ['command', null],
    'http after cooldown' => ['http', '2026-11-18 01:00:00 UTC'],
    'command after cooldown' => ['command', '2026-11-18 01:00:00 UTC'],
]);
