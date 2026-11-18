<?php

use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits;
use App\Services\Pixelforge\Client;
use App\Services\Pixelforge\ProviderCooldown;
use App\Services\Pixelforge\ProviderException;
use App\Services\UsageReconciliation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'test-key', 'services.pixelforge.webhook_secret' => 'whsec_test',
        'services.pixelforge.account_scope' => 'cooldown-test-'.Str::uuid(), 'cache.default' => 'redis', 'cache.prefix' => 'cooldown-test-'.Str::uuid().':']);
});

test('an account rate limit defers another reserved generation without consuming retries or releasing credit', function (int $seconds) {
    $this->travelTo('2026-11-18T12:00:00.500000Z');
    $first = Generation::factory()->create();
    $user = User::factory()->withCredits()->create();
    $second = reservedGeneration($user);
    $before = app(Credits::class)->forUser($user);
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => (string) $seconds])]);

    app()->call([new SyncGeneration($first->id), 'handle']);
    app()->call([new SyncGeneration($second->id), 'handle']);

    Http::assertSentCount(1);
    expect($second->refresh()->status)->toBe('queued');
    expect($second->sync_attempts)->toBe(0);
    expect($second->provider_id)->toBeNull();
    expect($second->user_charge)->toBeNull();
    expect(app(Credits::class)->forUser($user))->toBe($before);
})->with([2, 5, 120, 300]);

test('a generation rate limit prevents a reporting request', function () {
    $this->travelTo('2026-11-18T12:00:00.500000Z');
    Http::fake(['*/v1/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120'])]);
    app()->call([new SyncGeneration(Generation::factory()->create()->id), 'handle']);

    $result = app(UsageReconciliation::class)->compare(User::factory()->create(['is_operator' => true]), '2026-11-18', '2026-11-18');

    Http::assertSentCount(1);
    expect($result['state'])->toBe('unverified');
    expect($result['message'])->toContain('paused until');
});

test('a reporting rate limit defers scheduler and manual resume without an outbound create', function () {
    $this->travelTo('2026-11-18T12:00:00.500000Z');
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    $before = app(Credits::class)->forUser($user);
    Http::fake(['*/v1/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120'])]);
    app(UsageReconciliation::class)->compare(User::factory()->create(['is_operator' => true]), '2026-11-18', '2026-11-18');

    Artisan::call('pixelforge:reconcile');
    $this->actingAs($user)->post(route('generations.retry', $generation))->assertRedirect();

    Http::assertSentCount(1);
    expect($generation->refresh()->retry_not_before->format('H:i:s'))->toBe('12:02:01');
    expect($generation->sync_attempts)->toBe(0);
    expect(app(Credits::class)->forUser($user))->toBe($before);
});

test('CLI usage honors the same account cooldown as reporting', function () {
    $this->travelTo('2026-11-18T12:00:00.500000Z');
    Http::fake(['*/v1/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120'])]);
    app(UsageReconciliation::class)->compare(User::factory()->create(['is_operator' => true]), '2026-11-18', '2026-11-18');

    $this->artisan('pixelforge:usage', ['start_date' => '2026-11-18', 'end_date' => '2026-11-18'])->assertExitCode(1);

    Http::assertSentCount(1);
});

test('repeated reporting respects a scalar deadline with real Redis and restricted deserialization', function () {
    $this->travelTo('2026-11-18T12:00:00.500000Z');
    expect(config('cache.serializable_classes'))->toBeFalse();
    $operator = User::factory()->create(['is_operator' => true]);
    Http::fake(['*/v1/usage*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120'])]);
    app(UsageReconciliation::class)->compare($operator, '2026-11-18', '2026-11-18');
    Cache::forgetDriver('redis');

    $result = app(UsageReconciliation::class)->compare($operator, '2026-11-18', '2026-11-18');

    Http::assertSentCount(1);
    expect($result['message'])->toContain('paused until');
});

test('authenticated endpoints stay blocked until the rounded account deadline including after a key change', function (string $fraction, int $seconds, string $deadline) {
    $this->travelTo('2026-11-18T12:00:00.'.$fraction.'Z');
    Http::fake(['*/v1/usage*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => (string) $seconds]),
        '*/v1/generations*' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null])]);
    $client = app(Client::class);
    expect(fn () => $client->usage('2026-11-18', '2026-11-18'))->toThrow(ProviderException::class);
    config(['services.pixelforge.api_key' => 'another-key-for-the-same-account']);
    $this->travelTo('2026-11-18T'.$deadline.'Z');
    $this->travel(-1)->microseconds();

    expect(fn () => $client->generations())->toThrow(ProviderException::class);
    Http::assertSentCount(1);
    $this->travel(1)->microseconds();
    $client->generations();
    Http::assertSentCount(2);
})->with([
    ['000000', 2, '12:00:02'], ['000001', 2, '12:00:03'], ['500000', 5, '12:00:06'],
    ['500000', 120, '12:02:01'], ['999999', 300, '12:05:01'],
]);

test('concurrency overload and daily quota responses do not pause unrelated account reads', function (string $code, int $status, string $delay) {
    $this->travelTo('2026-11-18T12:00:00.500Z');
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => $code]], $status, ['Retry-After' => $delay]),
        '*/v1/usage*' => Http::response(['object' => 'usage'])]);
    app()->call([new SyncGeneration(Generation::factory()->create()->id), 'handle']);

    app(Client::class)->usage('2026-11-18', '2026-11-18');

    Http::assertSentCount(2);
})->with([['concurrency_limit_exceeded', 429, '5'], ['overloaded', 503, '2'], ['daily_limit_exceeded', 429, '300']]);

test('public pricing remains available during an authenticated account cooldown', function () {
    $this->freezeSecond();
    Http::fake(['*/v1/usage*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120'])]);
    fakePricing();
    $client = app(Client::class);
    expect(fn () => $client->usage('2026-11-18', '2026-11-18'))->toThrow(ProviderException::class);

    expect($client->pricing()['currency'])->toBe('usd');

    Http::assertSentCount(2);
});

test('delayed processing webhook and operator resume cannot bypass the account cooldown', function () {
    $this->travelTo('2026-11-18T12:00:00.500Z');
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    $generation->update(['provider_id' => 'gen_delayed_shared_cooldown', 'submission_uncertain' => true]);
    Http::fake(['*/v1/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '300'])]);
    $client = app(Client::class);
    expect(fn () => $client->usage('2026-11-18', '2026-11-18'))->toThrow(ProviderException::class);
    sendPixelforgeEvent(generationEvent($generation, 'processing', 'evt_shared_cooldown'))->assertNoContent();
    $this->travelTo('2026-11-18T12:00:20Z');

    $generation->update(['next_sync_at' => null, 'sync_attempts' => 5]);
    $this->artisan('pixelforge:resume', ['generation_id' => $generation->id])->assertExitCode(0);
    app()->call([new SyncGeneration($generation->id), 'handle']);

    Http::assertSentCount(1);
    expect($generation->refresh()->retry_not_before->format('H:i:s'))->toBe('12:05:01');
    expect($generation->status)->toBe('processing');
    expect($generation->submission_uncertain)->toBeTrue();
    expect(app(Credits::class)->forUser($user)['reserved'])->toBe('3.000000000000000000');
});

test('invalid shared deadlines fail closed without outbound requests or financial settlement', function () {
    $this->freezeSecond();
    $generation = reservedGeneration(User::factory()->withCredits()->create());
    $cooldown = app(ProviderCooldown::class);
    Redis::connection('cache')->setex($cooldown->key(), 30, 'O:15:"CarbonImmutable"');

    app()->call([new SyncGeneration($generation->id), 'handle']);

    Http::assertNothingSent();
    expect($generation->refresh()->error_code)->toBe('cooldown_unavailable');
    expect($generation->sync_attempts)->toBe(0);
    expect($generation->user_charge)->toBeNull();
    expect(app(Credits::class)->forUser($generation->user)['reserved'])->toBe('3.000000000000000000');
});

test('an unavailable cooldown store fails closed before contacting the provider', function () {
    config(['services.pixelforge.cooldown_connection' => 'not-configured']);
    $generation = reservedGeneration(User::factory()->withCredits()->create());

    app()->call([new SyncGeneration($generation->id), 'handle']);

    Http::assertNothingSent();
    expect($generation->refresh()->error_code)->toBe('cooldown_unavailable');
    expect($generation->status)->toBe('queued');
    expect($generation->sync_attempts)->toBe(0);
    expect($generation->submission_uncertain)->toBeFalse();
});

test('a read endpoint overload cooldown does not prevent unrelated generation submission', function () {
    $this->travelTo('2026-11-18T12:00:00.500Z');
    $operator = User::factory()->create(['is_operator' => true]);
    $generation = Generation::factory()->create();
    Http::fake(['*/v1/usage*' => Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '120']),
        '*/v1/generations' => Http::response(pixelforgeSnapshot($generation, 'queued'), 202)]);
    $service = app(UsageReconciliation::class);
    $service->compare($operator, '2026-11-18', '2026-11-18');
    $service->compare($operator, '2026-11-18', '2026-11-18');
    app()->call([new SyncGeneration($generation->id), 'handle']);

    Http::assertSentCount(2);
    expect($generation->refresh()->provider_id)->not->toBeNull();
});
