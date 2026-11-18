<?php

use App\Services\Pixelforge\ProviderCooldown;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

function cooldownProcess(string $code, array $arguments = []): Process
{
    $bootstrap = <<<'PHP'
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\Http::preventStrayRequests();
    config(['services.pixelforge.api_key' => 'synthetic-key', 'services.pixelforge.account_scope' => $argv[1], 'cache.default' => 'array']);
    Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse('2026-11-18T12:00:00.500Z'));
    PHP;

    return (new Process([PHP_BINARY, '-r', $bootstrap."\n".$code, config('services.pixelforge.account_scope'), ...$arguments], base_path(), [
        'APP_ENV' => 'testing', 'DB_DATABASE' => 'testing', 'DB_URL' => '', 'PIXELFORGE_API_KEY' => '', 'PIXELFORGE_WEBHOOK_SECRET' => '',
    ]))->setTimeout(20);
}

test('concurrent observed responses preserve the latest PostgreSQL and scalar Redis deadlines atomically', function () {
    $this->travelTo('2026-11-18T12:00:00.500Z');
    $redis = Redis::connection('cache');
    $barrier = 'cooldown-barrier:'.bin2hex(random_bytes(10));
    $worker = <<<'PHP'
    $redis = Illuminate\Support\Facades\Redis::connection('cache');
    Illuminate\Support\Facades\Http::fake(['*/v1/usage*' => function () use ($redis, $argv) {
        $redis->rpush($argv[2].':ready', 'ready');
        if (!$redis->blpop([$argv[2].':go'], 10)) { throw new RuntimeException('Missing start barrier'); }
        return Illuminate\Support\Facades\Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => $argv[3]]);
    }]);
    $response = Illuminate\Support\Facades\Http::get(config('services.pixelforge.base_url').'/usage');
    echo app(App\Services\Pixelforge\ProviderCooldown::class)->extend((int) $response->header('Retry-After'));
    Illuminate\Support\Facades\Http::assertSentCount(1);
    PHP;
    $processes = [cooldownProcess($worker, [$barrier, '300']), cooldownProcess($worker, [$barrier, '2'])];
    try {
        foreach ($processes as $process) {
            $process->start();
        }
        $limit = microtime(true) + 10;
        while ($redis->llen($barrier.':ready') !== 2 && microtime(true) < $limit) {
            usleep(10000);
        }
        expect($redis->llen($barrier.':ready'))->toBe(2, 'Both requests must be in flight before either response is released.');
        $redis->rpush($barrier.':go', 'go', 'go');
        foreach ($processes as $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            expect((int) $process->getOutput())->toBeGreaterThanOrEqual(CarbonImmutable::parse('2026-11-18T12:00:03Z')->getTimestamp())->toBeLessThanOrEqual(CarbonImmutable::parse('2026-11-18T12:05:01Z')->getTimestamp());
        }
        $cooldown = app(ProviderCooldown::class);
        $expected = CarbonImmutable::parse('2026-11-18T12:05:01Z')->getTimestamp();
        expect($redis->get($cooldown->key()))->toBe((string) $expected);
        expect($cooldown->extend(5))->toBe($expected);
        expect($redis->ttl($cooldown->key()))->toBeGreaterThanOrEqual(300);

        expect((int) DB::table('provider_cooldowns')->where('key', $cooldown->key())->value('deadline'))->toBe($expected);
        $redis->del($cooldown->key());
        $redis->disconnect();
        Redis::purge('cache');
        expect(app(ProviderCooldown::class)->deadline('POST', 'generations'))->toBe($expected);
        $reader = cooldownProcess(<<<'PHP'
            try { app(App\Services\Pixelforge\Client::class)->generations(); }
            catch (App\Services\Pixelforge\ProviderException $e) { echo json_encode(['deferred' => $e->deferred, 'deadline' => $e->retryNotBefore]); }
            Illuminate\Support\Facades\Http::assertNothingSent();
            PHP);
        expect($reader->run())->toBe(0, $reader->getErrorOutput());
        expect(json_decode($reader->getOutput(), true))->toBe(['deferred' => true, 'deadline' => $expected]);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        Redis::connection('cache')->del($barrier.':ready', $barrier.':go', app(ProviderCooldown::class)->key());
    }
});

test('a committed observation marker blocks independent processes until its response is recorded', function () {
    $this->travelTo('2026-11-18T12:00:00.500Z');
    $cooldown = app(ProviderCooldown::class);
    $token = $cooldown->begin('GET', 'usage');
    $reader = cooldownProcess(<<<'PHP'
        try { app(App\Services\Pixelforge\Client::class)->generations(); }
        catch (App\Services\Pixelforge\ProviderException $e) { echo json_encode(['code' => $e->providerCode, 'deferred' => $e->deferred]); }
        Illuminate\Support\Facades\Http::assertNothingSent();
        PHP);
    expect($reader->run())->toBe(0, $reader->getErrorOutput());
    expect(json_decode($reader->getOutput(), true))->toBe(['code' => 'provider_request_pending', 'deferred' => true]);
    $cooldown->finish($token, 120);
    $reader = cooldownProcess(<<<'PHP'
        try { app(App\Services\Pixelforge\Client::class)->generations(); }
        catch (App\Services\Pixelforge\ProviderException $e) { echo json_encode(['code' => $e->providerCode, 'deferred' => $e->deferred]); }
        Illuminate\Support\Facades\Http::assertNothingSent();
        PHP);
    expect($reader->run())->toBe(0, $reader->getErrorOutput());
    expect(json_decode($reader->getOutput(), true))->toBe(['code' => 'provider_cooldown', 'deferred' => true]);
});

test('a failed response commit remains blocked across PHP processes after PostgreSQL becomes writable', function () {
    $this->travelTo('2026-11-18T12:00:00.500Z');
    $writer = cooldownProcess(<<<'PHP'
        Illuminate\Support\Facades\Http::fake(['*/v1/usage*' => function () {
            Illuminate\Support\Facades\DB::connection('pixelforge_cooldowns')->statement('SET default_transaction_read_only = on');
            return Illuminate\Support\Facades\Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120']);
        }]);
        try { app(App\Services\Pixelforge\Client::class)->usage('2026-11-18', '2026-11-18'); }
        catch (App\Services\Pixelforge\ProviderException $e) { echo $e->providerCode; }
        Illuminate\Support\Facades\Http::assertSentCount(1);
        PHP);
    expect($writer->run())->toBe(0, $writer->getErrorOutput());
    expect($writer->getOutput())->toBe('cooldown_unavailable');
    $reader = cooldownProcess(<<<'PHP'
        try { app(App\Services\Pixelforge\Client::class)->generations(); }
        catch (App\Services\Pixelforge\ProviderException $e) { echo json_encode(['code' => $e->providerCode, 'deferred' => $e->deferred]); }
        Illuminate\Support\Facades\Http::assertNothingSent();
        PHP);
    expect($reader->run())->toBe(0, $reader->getErrorOutput());
    expect(json_decode($reader->getOutput(), true))->toBe(['code' => 'provider_request_pending', 'deferred' => true]);
    $row = DB::table('provider_cooldowns')->where('key', app(ProviderCooldown::class)->key())->first();
    expect($row->pending_token)->not->toBeNull();
});
