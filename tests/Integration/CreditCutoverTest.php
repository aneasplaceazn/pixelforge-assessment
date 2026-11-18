<?php

use App\Actions\ApplyGeneration;
use App\Jobs\ProcessWebhookEvent;
use App\Models\CreditEntry;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Credits;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

test('the reproduced old worker reservation can be repaired explicitly once without changing its bill', function () {
    require_once __DIR__.'/../Fixtures/LegacyApplyGeneration.php';
    $user = User::factory()->create();
    app(Credits::class)->purchase($user, '5', (string) Str::uuid());
    $generation = reservedGeneration($user);
    $snapshot = pixelforgeSnapshot($generation);
    app(Tests\Fixtures\ApplyGeneration::class)->handle($snapshot);
    app(ApplyGeneration::class)->handle($snapshot);
    expect(app(Credits::class)->forUser($user))->toBe(['available' => '2.000000000000000000', 'reserved' => '3.000000000000000000', 'outstanding' => '0.000000000000000000']);
    $bill = $generation->refresh()->getAttributes();

    foreach ([1, 2] as $attempt) {
        DB::transaction(function () use ($generation): void {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            app(Credits::class)->settle($locked);
        });
    }

    expect($generation->refresh()->getAttributes())->toBe($bill);
    expect(app(Credits::class)->forUser($user))->toBe(['available' => '4.985968750000000000', 'reserved' => '0.000000000000000000', 'outstanding' => '0.000000000000000000']);
    expect(CreditEntry::where('generation_id', $generation->id)->where('kind', 'settle')->sole()->amount)->toBe('0.014031250000000000');
    expect(CreditEntry::where('generation_id', $generation->id)->where('kind', 'release')->sole()->amount)->toBe('2.985968750000000000');
    Http::assertNothingSent();
});

test('database maintenance blocks submissions workers and scheduling across processes while health stays available', function () {
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'database']);
    $queue = 'cutover-test-'.bin2hex(random_bytes(8));
    $id = 'evt_'.$queue;
    WebhookEvent::create(['id' => $id, 'type' => 'ping', 'payload' => ['object' => 'event']]);
    ProcessWebhookEvent::dispatch($id)->onConnection('redis')->onQueue($queue);
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
        'APP_MAINTENANCE_DRIVER' => 'cache', 'APP_MAINTENANCE_STORE' => 'database', 'CACHE_STORE' => 'array', 'PIXELFORGE_API_KEY' => '', 'PIXELFORGE_WEBHOOK_SECRET' => ''];
    $down = new Process([PHP_BINARY, 'artisan', 'down', '--retry=60', '--no-interaction'], base_path(), $env);
    $worker = fn () => new Process([PHP_BINARY, 'artisan', 'queue:work', 'redis', '--queue='.$queue, '--stop-when-empty', '--max-time=1', '--sleep=1', '--tries=1', '--timeout=10', '--no-interaction'], base_path(), $env);
    try {
        expect($down->run())->toBe(0, $down->getErrorOutput());
        expect(app()->isDownForMaintenance())->toBeTrue();
        $this->get('/up')->assertOk();
        $this->actingAs(User::factory()->create())->post(route('generations.store'), generationSettings())->assertServiceUnavailable();
        foreach (app(Schedule::class)->events() as $event) {
            expect($event->isDue(app()))->toBeFalse();
        }
        $process = $worker();
        $process->setTimeout(15);
        expect($process->run())->toBe(0, $process->getErrorOutput());
        expect(WebhookEvent::findOrFail($id)->processed_at)->toBeNull();
        expect(Queue::connection('redis')->size($queue))->toBe(1);
        $this->assertDatabaseCount('generations', 0);
    } finally {
        Artisan::call('up');
    }
    $process = $worker();
    $process->setTimeout(15);
    expect($process->run())->toBe(0, $process->getErrorOutput());
    expect(WebhookEvent::findOrFail($id)->processed_at)->not->toBeNull();
    expect(Queue::connection('redis')->size($queue))->toBe(0);
    Http::assertNothingSent();
});
