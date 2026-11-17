<?php

use App\Actions\ApplyGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'test-key']);
    Http::preventStrayRequests();
});

test('submission sends immutable parameters and a stable idempotency key', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::response(pixelforgeSnapshot($generation, 'queued'), 202)]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->provider_id)->toBe('gen_'.$generation->id);
    expect($generation->fresh()->user_charge)->toBeNull();
    Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $generation->request_key) && $request->data() === $generation->providerParameters());
});

test('polling settles exact cost using generation rates rather than current pricing', function () {
    $generation = Generation::factory()->create(['provider_id' => 'gen_test']);
    $snapshot = pixelforgeSnapshot($generation);
    $snapshot['rates']['output_tokens'] = '20.00';
    Http::fake(['*/v1/generations/gen_test' => Http::response($snapshot)]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->status)->toBe('succeeded');
    expect($generation->fresh()->provider_cost)->toBe('0.005625000000000000');
    expect($generation->fresh()->user_charge)->toBe('0.007031250000000000');
});

test('definitive submission rejection is free and never retried automatically', function (int $status, string $code) {
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => $code]], $status)]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->status)->toBe('failed');
    expect($generation->fresh()->user_charge)->toBe('0.000000000000000000');
    expect($generation->fresh()->next_sync_at)->toBeNull();
})->with([[401, 'invalid_api_key'], [422, 'validation_failed'], [409, 'idempotency_key_reused'], [400, 'content_policy_violation'], [429, 'daily_limit_exceeded']]);

test('transient rejections respect Retry-After and remain uncharged', function (int $status, string $code) {
    $this->freezeTime();
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => $code]], $status, ['Retry-After' => '120'])]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->status)->toBe('queued');
    expect($generation->fresh()->user_charge)->toBeNull();
    expect($generation->fresh()->next_sync_at->timestamp)->toBe(now()->addSeconds(120)->timestamp);
})->with([[429, 'rate_limit_exceeded'], [429, 'concurrency_limit_exceeded'], [503, 'overloaded']]);

test('lost responses are retried with the same key and never declared free failures', function () {
    $this->freezeTime();
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => Http::sequence()->pushFailedConnection()->push(pixelforgeSnapshot($generation), 202)]);
    $job = new SyncGeneration($generation->id);
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    expect($generation->fresh()->submission_uncertain)->toBeTrue();
    $this->travel(20)->seconds();

    $job->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->status)->toBe('succeeded');
    expect($generation->fresh()->user_charge)->toBe('0.014031250000000000');
    Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $generation->request_key));
});

test('automatic retries stop after five attempts', function () {
    $generation = Generation::factory()->create(['sync_attempts' => 4]);
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'overloaded']], 503)]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->next_sync_at)->toBeNull();
    expect($generation->fresh()->sync_attempts)->toBe(5);
    expect($generation->fresh()->user_charge)->toBeNull();
});

test('a missing generation during polling is unresolved rather than a false failure', function () {
    $generation = Generation::factory()->create(['provider_id' => 'gen_test']);
    Http::fake(['*/v1/generations/gen_test' => Http::response(['error' => ['code' => 'not_found']], 404)]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->status)->toBe('queued');
    expect($generation->fresh()->user_charge)->toBeNull();
});

test('malformed success responses do not create a charge', function () {
    $generation = Generation::factory()->create();
    $snapshot = pixelforgeSnapshot($generation);
    unset($snapshot['usage']['reasoning_tokens']);
    Http::fake(['*/v1/generations' => Http::response($snapshot, 202)]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->user_charge)->toBeNull();
    expect($generation->fresh()->submission_uncertain)->toBeTrue();
});

test('an early success webhook wins over a late submission error', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/generations' => function () use ($generation) {
        app(ApplyGeneration::class)->handle(pixelforgeSnapshot($generation));

        return Http::response(['error' => ['code' => 'overloaded']], 503);
    }]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->status)->toBe('succeeded');
    expect($generation->fresh()->user_charge)->toBe('0.014031250000000000');
    expect($generation->fresh()->error_code)->toBeNull();
});

test('a rejection after an uncertain submission cannot declare the accepted work free', function () {
    $generation = Generation::factory()->create(['submission_uncertain' => true]);
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->status)->toBe('queued');
    expect($generation->fresh()->user_charge)->toBeNull();
    expect($generation->fresh()->next_sync_at)->toBeNull();
});

test('not-yet-due jobs do not bypass provider backoff', function () {
    $generation = Generation::factory()->create(['next_sync_at' => now()->addMinutes(2)]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    Http::assertNothingSent();
    expect($generation->fresh()->sync_attempts)->toBe(0);
});

test('the shared request budget throttles before contacting the provider', function () {
    RateLimiter::hit('pixelforge:requests', 60);
    for ($attempt = 1; $attempt < 45; $attempt++) {
        RateLimiter::hit('pixelforge:requests', 60);
    }
    $generation = Generation::factory()->create();

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    Http::assertNothingSent();
    expect($generation->fresh()->error_code)->toBe('local_rate_limit');
});

test('manual resume after exhausted retries cannot bypass the provider cooldown', function () {
    $this->freezeTime();
    $generation = Generation::factory()->create(['sync_attempts' => 4]);
    Http::fake(['*/v1/generations' => Http::sequence()
        ->push(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '300'])
        ->push(pixelforgeSnapshot($generation, 'queued'), 202)]);
    $job = new SyncGeneration($generation->id);
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Queue::fake([SyncGeneration::class]);
    $this->actingAs($generation->user)->post(route('generations.retry', $generation))->assertRedirect();
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Http::assertSentCount(1);
    $this->travel(299)->seconds();
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Http::assertSentCount(1);
    $this->travel(1)->seconds();
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $generation->request_key));
    expect($generation->fresh()->provider_id)->not->toBeNull();
});

test('delayed processing events preserve the retrieval cooldown', function () {
    $this->freezeTime();
    $generation = Generation::factory()->create(['provider_id' => 'gen_cooldown', 'status' => 'processing']);
    Http::fake(['*/v1/generations/gen_cooldown' => Http::sequence()
        ->push(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120'])
        ->push(pixelforgeSnapshot($generation))]);
    $job = new SyncGeneration($generation->id);
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    app(ApplyGeneration::class)->handle(pixelforgeSnapshot($generation, 'processing'));
    $this->travel(119)->seconds();
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    Http::assertSentCount(1);
    $this->travel(1)->seconds();
    $job->handle(app(Client::class), app(ApplyGeneration::class));
    expect($generation->fresh()->status)->toBe('succeeded');
});
