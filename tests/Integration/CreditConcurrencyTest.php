<?php

use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Credits;
use App\Services\FinancialReports;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

/** Real independent connections are held at an observed PostgreSQL lock barrier. */
function creditRace(string $body, array $arguments, Closure $lock): array
{
    $bootstrap = <<<'PHP'
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['services.pixelforge.api_key' => 'test-key', 'services.pixelforge.webhook_secret' => 'whsec_test', 'cache.default' => 'array', 'session.driver' => 'array']);
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Queue::fake();
    Illuminate\Support\Facades\DB::select("select set_config('application_name', ?, false)", [$argv[1]]);
    $input = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
    PHP;
    $processes = [];
    $name = 'credit-race-'.bin2hex(random_bytes(6));
    DB::beginTransaction();
    $lock();
    try {
        foreach ($arguments as $argument) {
            $process = new Process([PHP_BINARY, '-r', $bootstrap."\n".$body, $name, json_encode($argument)], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '', 'PIXELFORGE_API_KEY' => '', 'PIXELFORGE_WEBHOOK_SECRET' => '',
            ]);
            $process->setTimeout(25);
            $process->start();
            $processes[] = $process;
        }
        assertBlockedPostgresWorkers($name, count($arguments));
    } finally {
        DB::commit();
    }
    $outputs = [];
    foreach ($processes as $process) {
        expect($process->wait())->toBe(0, $process->getErrorOutput().$process->getOutput());
        $outputs[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    return $outputs;
}

test('simultaneous HTTP reservations cannot overspend and identical submissions reserve once', function (array $amounts, bool $sameKey, string $expectedReserved, int $accepted, int $generations) {
    $user = User::factory()->create();
    app(Credits::class)->purchase($user, '5', (string) Str::uuid());
    $key = (string) Str::uuid();
    $arguments = array_map(fn ($amount) => ['user' => $user->id, 'amount' => $amount, 'key' => $sameKey ? $key : (string) Str::uuid()], $amounts);
    $worker = <<<'PHP'
    $user = App\Models\User::findOrFail($input['user']);
    Illuminate\Support\Facades\Auth::login($user);
    $settings = ['prompt' => 'Concurrent reservation', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];
    Illuminate\Support\Facades\Cache::put('generation-quote:'.$input['key'], ['user_id' => $user->id, 'request_key' => $input['key'], 'parameters_hash' => hash('sha256', json_encode($settings)), 'pricing' => [], 'estimate' => ['provider_cost' => bcdiv($input['amount'], '1.25', 18), 'user_charge' => $input['amount']], 'expires_at' => time() + 300], 300);
    $request = Illuminate\Http\Request::create('/generations', 'POST', [...$settings, 'quote' => $input['key']], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    echo json_encode(['status' => $response->getStatusCode(), 'jobs' => Illuminate\Support\Facades\Queue::pushed(App\Jobs\SyncGeneration::class)->count()]);
    PHP;
    $results = creditRace($worker, $arguments, fn () => User::query()->lockForUpdate()->findOrFail($user->id));
    expect(collect($results)->where('status', 302)->count())->toBe($accepted);
    expect(collect($results)->where('status', 422)->count())->toBe(count($amounts) - $accepted);
    expect(collect($results)->sum('jobs'))->toBe($generations);
    expect(app(Credits::class)->forUser($user)['reserved'])->toBe(bcadd($expectedReserved, '0', 18));
    expect(app(Credits::class)->forUser($user)['available'])->toBe(bcsub('5', $expectedReserved, 18));
    $this->assertDatabaseCount('generations', $generations);
    expect(CreditEntry::where('kind', 'reserve')->count())->toBe($generations);
})->with([
    'two three dollar reservations compete for five dollars' => [['3', '3'], false, '3', 1, 1],
    'same logical request submitted twice' => [['3', '3'], true, '3', 2, 1],
    'several distinct requests fit exactly' => [['1', '1.5', '2.5'], false, '5', 3, 3],
]);

test('concurrent repeated and distinct fake purchases retain every explicit purchase exactly once', function () {
    $user = User::factory()->create();
    $key = (string) Str::uuid();
    $worker = <<<'PHP'
    app(App\Services\Credits::class)->purchase(App\Models\User::findOrFail($input['user']), $input['amount'], $input['key']);
    echo '{}';
    PHP;
    creditRace($worker, [
        ['user' => $user->id, 'amount' => '5', 'key' => $key],
        ['user' => $user->id, 'amount' => '5', 'key' => $key],
        ['user' => $user->id, 'amount' => '10', 'key' => (string) Str::uuid()],
    ], fn () => User::query()->lockForUpdate()->findOrFail($user->id));
    expect(app(Credits::class)->forUser($user)['available'])->toBe('15.000000000000000000');
    expect(CreditEntry::where('kind', 'purchase')->count())->toBe(2);
});

test('concurrent successes failure refunds duplicate events and a retrying worker settle credits once', function () {
    $user = User::factory()->withCredits()->create();
    $success = reservedGeneration($user, '3');
    $failure = reservedGeneration($user, '2');
    foreach (['evt_credit_one', 'evt_credit_two'] as $id) {
        WebhookEvent::create(['id' => $id, 'type' => 'generation.succeeded', 'payload' => ['data' => ['object' => pixelforgeSnapshot($success)]]]);
    }
    WebhookEvent::create(['id' => 'evt_credit_failure', 'type' => 'generation.failed', 'payload' => ['data' => ['object' => pixelforgeSnapshot($failure, 'failed')]]]);
    $worker = <<<'PHP'
    if (isset($input['event'])) {
        app(App\Actions\ProcessWebhook::class)->handle($input['event']);
    } else {
        Illuminate\Support\Facades\Http::fake(['*' => Illuminate\Support\Facades\Http::response($input['snapshot'], 202)]);
        app()->call([new App\Jobs\SyncGeneration($input['generation']), 'handle']);
    }
    echo '{}';
    PHP;
    creditRace($worker, [['event' => 'evt_credit_one'], ['event' => 'evt_credit_one'], ['event' => 'evt_credit_two'], ['event' => 'evt_credit_failure'], ['generation' => $success->id, 'snapshot' => pixelforgeSnapshot($success)]], function () use ($success, $failure) {
        Generation::query()->whereIn('id', [$success->id, $failure->id])->orderBy('id')->lockForUpdate()->get();
    });
    expect(app(Credits::class)->forUser($user))->toBe(['available' => '19.985968750000000000', 'reserved' => '0.000000000000000000', 'outstanding' => '0.000000000000000000']);
    expect(CreditEntry::where('kind', 'settle')->count())->toBe(1);
    expect(CreditEntry::where('kind', 'release')->count())->toBe(2);
    expect($failure->fresh()->user_charge)->toBe('0.000000000000000000');
    expect(WebhookEvent::whereNotNull('processed_at')->count())->toBe(3);
});

test('the PostgreSQL ledger guard independently prevents simultaneous direct overspending', function () {
    $user = User::factory()->create();
    app(Credits::class)->purchase($user, '5', (string) Str::uuid());
    $account = CreditAccount::where('user_id', $user->id)->sole();
    $generations = Generation::factory()->for($user)->count(2)->create(['credit_account_id' => $account->id, 'estimated_cost' => '2.4', 'estimated_charge' => '3']);
    $worker = <<<'PHP'
    try {
        App\Models\CreditEntry::create(['credit_account_id' => $input['account'], 'generation_id' => $input['generation'], 'kind' => 'reserve', 'amount' => '3', 'available_delta' => '-3', 'reserved_delta' => '3', 'outstanding_delta' => '0']);
        echo '{"accepted":true}';
    } catch (Illuminate\Database\QueryException $e) {
        if ($e->getCode() !== '23514') { throw $e; }
        echo '{"accepted":false}';
    }
    PHP;
    $results = creditRace($worker, $generations->map(fn ($generation) => ['account' => $account->id, 'generation' => $generation->id])->all(), fn () => CreditAccount::query()->lockForUpdate()->findOrFail($account->id));
    expect(collect($results)->where('accepted', true)->count())->toBe(1);
    expect(app(Credits::class)->forUser($user)['available'])->toBe('2.000000000000000000');
    expect(app(Credits::class)->forUser($user)['reserved'])->toBe('3.000000000000000000');
});

test('operator totals days and users share one database snapshot during a concurrent settlement', function () {
    $generation = Generation::factory()->create();
    $operator = User::factory()->create(['is_operator' => true]);
    $settled = false;
    DB::listen(function ($query) use (&$settled, $generation) {
        if ($settled || ! str_contains($query->sql, 'COUNT(*) AS total')) {
            return;
        }
        $settled = true;
        $worker = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        $app = require getcwd().'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\Http::preventStrayRequests();
        app(App\Actions\ApplyGeneration::class)->handle(json_decode($argv[1], true));
        PHP;
        $process = new Process([PHP_BINARY, '-r', $worker, json_encode(pixelforgeSnapshot($generation))], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '', 'PIXELFORGE_API_KEY' => '', 'PIXELFORGE_WEBHOOK_SECRET' => '']);
        $process->setTimeout(15);
        expect($process->run())->toBe(0, $process->getErrorOutput());
    });
    $report = app(FinancialReports::class)->build($operator, now('UTC')->toDateString(), now('UTC')->toDateString());
    expect($settled)->toBeTrue();
    expect($report['totals']->succeeded)->toBe(0);
    expect($report['days']->first()->succeeded)->toBe(0);
    expect($report['users']->first()->succeeded)->toBe(0);
    expect($report['pending'])->toBe(1);
    expect($generation->fresh()->status)->toBe('succeeded');
    $next = app(FinancialReports::class)->build($operator, now('UTC')->toDateString(), now('UTC')->toDateString());
    expect($next['totals']->charges)->toBe('0.014031250000000000');
});
