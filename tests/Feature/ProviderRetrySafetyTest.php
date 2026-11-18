<?php

use App\Jobs\SyncGeneration;
use App\Models\User;
use App\Services\Credits;
use App\Services\Pixelforge\Client;
use App\Services\Pixelforge\ProviderCooldown;
use App\Services\Pixelforge\ProviderException;
use App\Services\UsageReconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'test-key', 'cache.default' => 'redis', 'cache.prefix' => 'retry-safety-'.Str::uuid().':']);
    $this->travelTo('2026-11-18T12:00:00.500000Z');
});

test('a real Redis write rejection preserves the deadline across reporting generation and CLI recovery', function () {
    $redis = Redis::connection('cache');
    $name = 'retry-test-'.Str::uuid();
    $password = bin2hex(random_bytes(16));
    $redis->command('acl', ['SETUSER', $name, 'on', '>'.$password, '~*', '+@all']);
    config(['database.redis.cooldown_fault' => [...config('database.redis.cache'), 'url' => null, 'username' => $name, 'password' => $password], 'services.pixelforge.cooldown_connection' => 'cooldown_fault']);
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
    $cooldown = app(ProviderCooldown::class);
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    $original = $generation->refresh()->getAttributes();
    $balance = app(Credits::class)->forUser($user);
    Http::fake(['*/v1/usage*' => function () use ($redis, $name) {
        $redis->command('acl', ['SETUSER', $name, '-eval']);

        return Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120']);
    }, '*/v1/generations*' => Http::response(pixelforgeSnapshot($generation, 'queued'), 202)]);

    try {
        $result = app(UsageReconciliation::class)->compare(User::factory()->create(['is_operator' => true]), '2026-11-18', '2026-11-18');
        expect($result['message'])->toContain('cooldown_unavailable');
        expect($redis->get($cooldown->key()))->toBeNull();
        $redis->command('acl', ['SETUSER', $name, '+eval']);
        expect($cooldown->deadline('POST', 'generations'))->toBe(CarbonImmutable::parse('2026-11-18T12:02:01Z')->getTimestamp());
        app()->call([new SyncGeneration($generation->id), 'handle']);
        $this->artisan('pixelforge:usage', ['start_date' => '2026-11-18', 'end_date' => '2026-11-18'])->assertExitCode(1);
        Http::assertSentCount(1);
        expect($generation->refresh()->sync_attempts)->toBe(0);
        expect($generation->request_key)->toBe($original['request_key']);
        expect($generation->user_charge)->toBeNull();
        expect(app(Credits::class)->forUser($user))->toBe($balance);
        $this->travelTo('2026-11-18T12:02:00.999999Z');
        expect(fn () => app(Client::class)->create($generation))->toThrow(ProviderException::class);
        Http::assertSentCount(1);
        $this->travelTo('2026-11-18T12:02:01Z');
        app()->call([new SyncGeneration($generation->id), 'handle']);
        Http::assertSentCount(2);
        expect($generation->refresh()->provider_id)->not->toBeNull();
        expect(app(Credits::class)->forUser($user))->toBe($balance);
    } finally {
        Redis::purge('cooldown_fault');
        $redis->command('acl', ['DELUSER', $name]);
    }
});

test('local budget deferrals preserve the exhaustion boundary and resume when the budget recovers', function (bool $uncertain, string $status) {
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    $generation->update(['sync_attempts' => 4, 'submission_uncertain' => $uncertain, 'status' => $status, 'provider_id' => $status === 'processing' ? 'gen_budget_processing' : null]);
    $key = $generation->request_key;
    $balance = app(Credits::class)->forUser($user);
    Http::fake(['*/v1/generations*' => Http::response(pixelforgeSnapshot($generation, 'processing'), 202)]);
    foreach (['12:00:00.500000', '12:01:01.500000', '12:02:02.500000'] as $time) {
        $this->travelTo('2026-11-18T'.$time.'Z');
        RateLimiter::clear('pixelforge:requests');
        for ($i = 0; $i < 45; $i++) {
            RateLimiter::hit('pixelforge:requests', 60);
        }
        app()->call([new SyncGeneration($generation->id), 'handle']);
        expect($generation->refresh()->sync_attempts)->toBe(4);
        expect($generation->next_sync_at->isFuture())->toBeTrue();
        expect($generation->retry_not_before->format('u'))->toBe('000000');
        expect($generation->submission_uncertain)->toBe($uncertain);
        expect($generation->status)->toBe($status);
    }
    Http::assertNothingSent();
    expect($generation->request_key)->toBe($key);
    expect($generation->user_charge)->toBeNull();
    expect(app(Credits::class)->forUser($user))->toBe($balance);
    $this->travelTo($generation->next_sync_at);
    RateLimiter::clear('pixelforge:requests');
    app()->call([new SyncGeneration($generation->id), 'handle']);
    Http::assertSentCount(1);
    expect($generation->refresh()->status)->toBe('processing');
    expect($generation->next_sync_at)->not->toBeNull();
    expect(app(Credits::class)->forUser($user))->toBe($balance);
})->with([[false, 'queued'], [true, 'queued'], [true, 'processing']]);

test('a genuine outbound failure still exhausts the fifth provider attempt', function () {
    $generation = reservedGeneration(User::factory()->withCredits()->create());
    $generation->update(['sync_attempts' => 4]);
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2'])]);
    app()->call([new SyncGeneration($generation->id), 'handle']);
    Http::assertSentCount(1);
    expect($generation->refresh()->sync_attempts)->toBe(5);
    expect($generation->next_sync_at)->toBeNull();
    expect($generation->retry_not_before->format('H:i:s'))->toBe('12:02:41');
    expect(app(Credits::class)->forUser($generation->user)['reserved'])->toBe('3.000000000000000000');
});

test('local limiter lock failures are no send deferrals with a future recovery deadline', function () {
    $this->travelBack();
    $generation = reservedGeneration(User::factory()->withCredits()->create());
    $generation->update(['sync_attempts' => 4]);
    $lock = Cache::lock('pixelforge:budget-lock', 10);
    expect($lock->get())->toBeTrue();
    try {
        app()->call([new SyncGeneration($generation->id), 'handle']);
        Http::assertNothingSent();
        expect($generation->refresh()->sync_attempts)->toBe(4);
        expect($generation->error_code)->toBe('local_limiter_unavailable');
        expect($generation->next_sync_at->isFuture())->toBeTrue();
        expect(app(Credits::class)->forUser($generation->user)['reserved'])->toBe('3.000000000000000000');
    } finally {
        $lock->release();
    }
});

test('database preflight read and write failures fail closed and recover without sending early', function (string $setting, string $restore) {
    $cooldown = app(ProviderCooldown::class);
    $cooldown->deadline('GET', 'usage');
    $database = DB::connection('pixelforge_cooldowns');
    Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage'])]);
    $database->statement($setting);
    try {
        expect(fn () => app(Client::class)->usage('2026-11-18', '2026-11-18'))->toThrow(ProviderException::class);
        Http::assertNothingSent();
    } finally {
        $database->statement($restore);
    }
    app(Client::class)->usage('2026-11-18', '2026-11-18');
    Http::assertSentCount(1);
})->with([
    ['SET search_path TO unavailable_cooldown_schema', 'SET search_path TO public'],
    ['SET default_transaction_read_only = on', 'SET default_transaction_read_only = off'],
]);

test('failed durable response persistence retains a shared observation barrier after storage recovers', function () {
    $cooldown = app(ProviderCooldown::class);
    Http::fake(['*/v1/usage*' => function () {
        DB::connection('pixelforge_cooldowns')->statement('SET default_transaction_read_only = on');

        return Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120']);
    }, '*/v1/generations*' => Http::response(['object' => 'list'])]);
    try {
        expect(fn () => app(Client::class)->usage('2026-11-18', '2026-11-18'))->toThrow(ProviderException::class);
    } finally {
        DB::connection('pixelforge_cooldowns')->statement('SET default_transaction_read_only = off');
    }
    $row = DB::connection('pixelforge_cooldowns')->table('provider_cooldowns')->where('key', $cooldown->key())->first();
    expect($row->pending_token)->not->toBeNull();
    expect(fn () => app(Client::class)->generations())->toThrow(ProviderException::class, 'Another provider request');
    Http::assertSentCount(1);
    // Model an explicitly authorized, locked repair with the known captured response deadline.
    DB::connection('pixelforge_cooldowns')->transaction(function () use ($row, $cooldown) {
        DB::connection('pixelforge_cooldowns')->table('provider_cooldowns')->where('key', $cooldown->key())->lockForUpdate()->first();
        $cooldown->finish($row->pending_token, 120);
    });
    $this->travelTo('2026-11-18T12:02:00.999999Z');
    expect(fn () => app(Client::class)->generations())->toThrow(ProviderException::class);
    Http::assertSentCount(1);
    $this->travelTo('2026-11-18T12:02:01Z');
    app(Client::class)->generations();
    Http::assertSentCount(2);
});

test('a later required generation deadline survives an exhausted local budget unchanged', function () {
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    $generation->update(['sync_attempts' => 4, 'retry_not_before' => '2026-11-18T12:05:01Z', 'submission_uncertain' => true]);
    for ($i = 0; $i < 45; $i++) {
        RateLimiter::hit('pixelforge:requests', 60);
    }

    app()->call([new SyncGeneration($generation->id), 'handle']);

    Http::assertNothingSent();
    expect($generation->refresh()->sync_attempts)->toBe(4);
    expect($generation->next_sync_at->format('H:i:s'))->toBe('12:05:01');
    expect($generation->retry_not_before->format('H:i:s'))->toBe('12:05:01');
    expect($generation->submission_uncertain)->toBeTrue();
    expect($generation->user_charge)->toBeNull();
    expect(app(Credits::class)->forUser($user)['reserved'])->toBe('3.000000000000000000');
});
