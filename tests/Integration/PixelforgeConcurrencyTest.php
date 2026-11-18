<?php

use App\Actions\ProcessWebhook;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Credits;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

test('parallel delivery of duplicate and distinct success events settles once in PostgreSQL', function () {
    $generation = Generation::factory()->create();
    foreach (['evt_one', 'evt_two'] as $id) {
        WebhookEvent::create(['id' => $id, 'type' => 'generation.succeeded', 'payload' => ['data' => ['object' => pixelforgeSnapshot($generation)]]]);
    }
    $worker = <<<'PHP'
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\DB::select("select set_config('application_name', ?, false)", [$argv[2]]);
    app(App\Actions\ProcessWebhook::class)->handle($argv[1]);
    PHP;
    $processes = [];
    $name = 'pixelforge-concurrency-'.bin2hex(random_bytes(5));
    DB::beginTransaction();
    Generation::query()->lockForUpdate()->findOrFail($generation->id);
    foreach (['evt_one', 'evt_one', 'evt_two', 'evt_two'] as $eventId) {
        $process = new Process([PHP_BINARY, '-r', $worker, $eventId, $name], base_path(), [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => config('database.connections.pgsql.database'),
        ]);
        $process->setTimeout(20);
        $process->start();
        $processes[] = $process;
    }
    try {
        assertBlockedPostgresWorkers($name, 4);
    } finally {
        DB::commit();
    }
    foreach ($processes as $process) {
        expect($process->wait())->toBe(0, $process->getErrorOutput());
    }

    expect($generation->fresh()->user_charge)->toBe('0.014031250000000000');
    expect(WebhookEvent::whereNotNull('processed_at')->count())->toBe(2);
    $this->assertDatabaseCount('generations', 1);
});

test('simultaneous signed HTTP deliveries insert one durable event and settle once', function () {
    $generation = Generation::factory()->create();
    $body = json_encode(generationEvent($generation, 'succeeded', 'evt_http_race'), JSON_THROW_ON_ERROR);
    $worker = <<<'PHP'
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Queue::fake();
    Illuminate\Support\Facades\DB::select("select set_config('application_name', ?, false)", [$argv[1]]);
    $body = $argv[2];
    $time = time();
    $signature = 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, 'whsec_test');
    $request = Illuminate\Http\Request::create('/webhooks/pixelforge', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $signature], $body);
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    exit($response->getStatusCode() === 204 ? 0 : 1);
    PHP;
    $name = 'pixelforge-http-race-'.bin2hex(random_bytes(5));
    $processes = [];
    DB::beginTransaction();
    DB::statement('LOCK TABLE webhook_events IN SHARE MODE');
    try {
        for ($i = 0; $i < 4; $i++) {
            $process = new Process([PHP_BINARY, '-r', $worker, $name, $body], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'PIXELFORGE_API_KEY' => '', 'PIXELFORGE_WEBHOOK_SECRET' => '']);
            $process->setTimeout(20);
            $process->start();
            $processes[] = $process;
        }
        assertBlockedPostgresWorkers($name, 4);
    } finally {
        DB::commit();
    }
    foreach ($processes as $process) {
        expect($process->wait())->toBe(0, $process->getErrorOutput());
    }
    $this->assertDatabaseCount('webhook_events', 1);
    app(ProcessWebhook::class)->handle('evt_http_race');
    expect($generation->fresh()->user_charge)->toBe('0.014031250000000000');
    expect(WebhookEvent::sole()->attempts)->toBe(1);
});

test('a real Redis worker consumes a deduplicated durable inbox job', function () {
    config(['cache.default' => 'redis']);
    $queue = 'pixelforge-test-'.bin2hex(random_bytes(8));
    $id = 'evt_'.$queue;
    WebhookEvent::create(['id' => $id, 'type' => 'ping', 'payload' => ['object' => 'event']]);
    ProcessWebhookEvent::dispatch($id)->onConnection('redis')->onQueue($queue);
    ProcessWebhookEvent::dispatch($id)->onConnection('redis')->onQueue($queue);
    expect(Queue::connection('redis')->size($queue))->toBe(1);
    $worker = new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue, '--stop-when-empty', '--tries=1', '--timeout=10'], base_path(), [
        'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'CACHE_STORE' => 'redis',
        'PIXELFORGE_API_KEY' => '', 'PIXELFORGE_WEBHOOK_SECRET' => '',
    ]);
    $worker->setTimeout(20);
    expect($worker->run())->toBe(0, $worker->getErrorOutput());
    expect(WebhookEvent::findOrFail($id)->processed_at)->not->toBeNull();
    expect(WebhookEvent::findOrFail($id)->attempts)->toBe(1);
    expect(Queue::connection('redis')->size($queue))->toBe(0);
    $this->assertDatabaseCount('failed_jobs', 0);
});

test('upgrading the original schema preserves records and active cooldown deadlines', function () {
    $this->artisan('migrate:rollback', ['--step' => 5, '--force' => true])->assertExitCode(0);
    $generation = Generation::factory()->create(['next_sync_at' => now()->addMinutes(5)]);
    $completed = Generation::factory()->create(['status' => 'succeeded', 'provider_cost' => '0.011225', 'user_charge' => '0.01403125', 'completed_at' => now(), 'next_sync_at' => null]);
    WebhookEvent::create(['id' => 'evt_before_upgrade', 'type' => 'ping', 'payload' => ['object' => 'event']]);

    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

    expect($generation->fresh()->retry_not_before->timestamp)->toBe($generation->fresh()->next_sync_at->timestamp);
    expect($generation->fresh()->prompt)->toBe($generation->prompt);
    expect($completed->fresh()->user_charge)->toBe('0.014031250000000000');
    expect(WebhookEvent::findOrFail('evt_before_upgrade')->attempts)->toBe(0);
    expect(WebhookEvent::findOrFail('evt_before_upgrade')->payload)->toBe(['object' => 'event']);
    $this->assertDatabaseCount('generations', 2);
});

test('the bonus upgrade preserves existing accounts historical billing and in flight submission parameters', function () {
    $this->artisan('migrate:rollback', ['--step' => 2, '--force' => true])->assertExitCode(0);
    $pending = Generation::factory()->create(['provider_id' => 'gen_before_credit_upgrade', 'submission_uncertain' => true, 'retry_not_before' => now()->addMinutes(5), 'sync_attempts' => 2]);
    $completed = Generation::factory()->for($pending->user)->create(['status' => 'succeeded', 'provider_cost' => '0.016812500', 'user_charge' => '0.021015625', 'completed_at' => now(), 'next_sync_at' => null]);
    $parameters = $pending->providerParameters();
    $userAttributes = $pending->user->getAttributes();
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    expect($pending->fresh()->providerParameters())->toBe($parameters);
    expect($pending->fresh()->request_key)->toBe($pending->request_key);
    expect($pending->fresh()->retry_not_before->timestamp)->toBe($pending->retry_not_before->timestamp);
    expect($pending->fresh()->credit_account_id)->toBeNull();
    expect($completed->fresh()->user_charge)->toBe('0.021015625000000000');
    expect($completed->fresh()->provider_cost)->toBe('0.016812500000000000');
    expect($completed->fresh()->provider_scope)->toBeNull();
    expect($pending->user->fresh()->is_operator)->toBeFalse();
    foreach ($userAttributes as $key => $value) {
        expect($pending->user->fresh()->getRawOriginal($key))->toBe($value);
    }
    expect(app(Credits::class)->forUser($pending->user))->toBe(['available' => '0.000000000000000000', 'reserved' => '0.000000000000000000', 'outstanding' => '0.000000000000000000']);
    $this->assertDatabaseCount('credit_entries', 0);
    $this->assertDatabaseCount('credit_accounts', 0);
});
