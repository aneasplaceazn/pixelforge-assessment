<?php

use App\Actions\ApplyGeneration;
use App\Actions\DeleteAccount;
use App\Jobs\SyncGeneration;
use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\CreditEntryKind;
use App\Models\Generation;
use App\Models\User;
use App\Services\Credits;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.pixelforge.api_key' => 'test-key', 'services.pixelforge.webhook_secret' => 'whsec_test']);
});

test('accounts begin with zero credits and unaffordable submissions never dispatch', function () {
    fakePricing();
    Queue::fake();
    $user = User::factory()->create();
    $this->actingAs($user);
    $quote = $this->postJson(route('generations.estimate'), generationSettings())->assertOk()->assertJsonPath('can_afford', false)->json('quote');
    $this->postJson(route('generations.store'), [...generationSettings(), 'quote' => $quote])->assertUnprocessable()->assertJsonValidationErrors('quote');
    $this->assertDatabaseCount('generations', 0);
    $this->assertDatabaseCount('credit_entries', 0);
    Queue::assertNothingPushed();
    expect(app(Credits::class)->forUser($user)['available'])->toBe('0.000000000000000000');
});

test('fake purchases are authorized validated durable and idempotent', function () {
    $key = (string) Str::uuid();
    $this->post(route('credits.store'), ['amount' => '5', 'purchase_key' => $key])->assertRedirect(route('login'));
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);
    foreach (['-1', '0', '5.01', 'NaN', '100'] as $amount) {
        $this->postJson(route('credits.store'), ['amount' => $amount, 'purchase_key' => $key])->assertUnprocessable();
    }
    $this->post(route('credits.store'), ['amount' => '5', 'purchase_key' => $key, 'user_id' => $other->id])->assertRedirect(route('credits.index'));
    $this->post(route('credits.store'), ['amount' => '5', 'purchase_key' => $key])->assertRedirect(route('credits.index'));
    $this->postJson(route('credits.store'), ['amount' => '10', 'purchase_key' => $key])->assertUnprocessable()->assertJsonValidationErrors('purchase_key');
    expect(app(Credits::class)->forUser($user)['available'])->toBe('5.000000000000000000');
    expect(app(Credits::class)->forUser($other)['available'])->toBe('0.000000000000000000');
    $this->assertDatabaseCount('credit_entries', 1);
    Http::assertNothingSent();
});

test('duplicate submissions reserve once and successful settlement releases the exact remainder', function () {
    fakePricing();
    Queue::fake([SyncGeneration::class]);
    $user = User::factory()->withCredits()->create();
    $this->actingAs($user);
    $quote = $this->postJson(route('generations.estimate'), generationSettings())->assertOk()->json();
    $payload = [...generationSettings(), 'quote' => $quote['quote']];
    $this->post(route('generations.store'), $payload)->assertRedirect(route('dashboard'));
    $this->post(route('generations.store'), $payload)->assertRedirect(route('dashboard'));
    $generation = Generation::sole();
    expect(app(Credits::class)->forUser($user)['reserved'])->toBe($quote['user_charge']);
    $event = generationEvent($generation, 'succeeded', 'evt_credit_success');
    sendPixelforgeEvent($event)->assertNoContent();
    sendPixelforgeEvent($event)->assertNoContent();
    sendPixelforgeEvent(generationEvent($generation, 'processing', 'evt_credit_late'))->assertNoContent();
    $charge = $generation->fresh()->user_charge;
    expect(app(Credits::class)->forUser($user))->toBe(['available' => bcsub('20', $charge, 18), 'reserved' => '0.000000000000000000', 'outstanding' => '0.000000000000000000']);
    expect(CreditEntry::where('generation_id', $generation->id)->orderBy('id')->toBase()->pluck('kind')->all())->toBe(['reserve', 'settle', 'release']);
    expect(CreditEntry::where('kind', 'release')->sole()->amount)->toBe(bcsub($quote['user_charge'], $charge, 18));
    expect($generation->fresh()->status)->toBe('succeeded');
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('credits.available', bcsub('20', $charge, 18))->where('generations.data.0.user_charge', $charge));
    Queue::assertPushed(SyncGeneration::class, 1);
});

test('failed generation releases all credits without a settlement even after late success', function () {
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    app(ApplyGeneration::class)->handle(pixelforgeSnapshot($generation, 'failed'));
    app(ApplyGeneration::class)->handle(pixelforgeSnapshot($generation, 'succeeded'));
    expect(app(Credits::class)->forUser($user)['available'])->toBe('20.000000000000000000');
    expect(app(Credits::class)->forUser($user)['reserved'])->toBe('0.000000000000000000');
    expect($generation->fresh()->user_charge)->toBe('0.000000000000000000');
    expect(CreditEntry::where('kind', 'settle')->count())->toBe(0);
    expect(CreditEntry::where('kind', 'release')->count())->toBe(1);
});

test('uncertain submission keeps the reservation through bounded retry pause and manual resume', function () {
    Queue::fake();
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    Http::fake(['*/v1/generations' => Http::failedConnection()]);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->travelTo($generation->fresh()->next_sync_at ?? now());
        app()->call([new SyncGeneration($generation->id), 'handle']);
    }
    expect($generation->fresh()->next_sync_at)->toBeNull();
    expect($generation->fresh()->submission_uncertain)->toBeTrue();
    expect(app(Credits::class)->forUser($user)['reserved'])->toBe('3.000000000000000000');
    expect(CreditEntry::where('kind', 'release')->count())->toBe(0);
    $this->actingAs($user)->post(route('generations.retry', $generation))->assertRedirect(route('dashboard'));
    expect($generation->fresh()->request_key)->toBe($generation->request_key);
    expect(CreditEntry::where('kind', 'reserve')->count())->toBe(1);
});

test('definitive provider rejection releases credits', function () {
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    Http::fake(['*/v1/generations' => Http::response(['error' => ['code' => 'content_policy_violation']], 400)]);
    app()->call([new SyncGeneration($generation->id), 'handle']);
    expect($generation->fresh()->status)->toBe('failed');
    expect(app(Credits::class)->forUser($user)['available'])->toBe('20.000000000000000000');
    expect(CreditEntry::where('kind', 'settle')->count())->toBe(0);
});

test('price increases preserve full charges without negative balances and purchases pay outstanding charges first', function () {
    $user = User::factory()->create();
    app(Credits::class)->purchase($user, '5', (string) Str::uuid());
    $generation = reservedGeneration($user, '5');
    $snapshot = pixelforgeSnapshot($generation);
    $snapshot['rates']['output_tokens'] = '20000.00';
    app(ApplyGeneration::class)->handle($snapshot);
    $charge = $generation->fresh()->user_charge;
    expect($charge)->toBe('7.000031250000000000');
    expect(app(Credits::class)->forUser($user))->toBe(['available' => '0.000000000000000000', 'reserved' => '0.000000000000000000', 'outstanding' => '2.000031250000000000']);
    expect(app(Credits::class)->canAfford($user, '0'))->toBeFalse();
    $key = (string) Str::uuid();
    app(Credits::class)->purchase($user, '5', $key);
    app(Credits::class)->purchase($user, '5', $key);
    expect(app(Credits::class)->forUser($user)['available'])->toBe('2.999968750000000000');
    expect(app(Credits::class)->forUser($user)['outstanding'])->toBe('0.000000000000000000');
    expect(CreditEntry::where('kind', 'debt_payment')->count())->toBe(1);
    expect($generation->fresh()->user_charge)->toBe($charge);
});

test('account deletion retains anonymous financial records and settles an in flight generation', function () {
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    $generation->update(['provider_id' => 'gen_deleted_pending', 'status' => 'processing', 'submission_uncertain' => true]);
    $snapshot = pixelforgeSnapshot($generation);
    $accountId = $generation->credit_account_id;
    app(DeleteAccount::class)->handle($user);
    expect(CreditAccount::findOrFail($accountId)->user_id)->toBeNull();
    app(ApplyGeneration::class)->handle($snapshot);
    expect($generation->fresh()->prompt)->toBe('');
    expect($generation->fresh()->user_id)->toBeNull();
    expect(app(Credits::class)->balances($accountId)['reserved'])->toBe('0.000000000000000000');
    expect(CreditEntry::where('generation_id', $generation->id)->count())->toBe(3);
});

test('unsubmitted account deletion refunds a reservation and historical records are never debited', function () {
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    $legacy = Generation::factory()->for($user)->create();
    app(ApplyGeneration::class)->handle(pixelforgeSnapshot($legacy));
    expect(CreditEntry::where('generation_id', $legacy->id)->count())->toBe(0);
    $accountId = $generation->credit_account_id;
    app(DeleteAccount::class)->handle($user);
    expect($generation->fresh()->status)->toBe('failed');
    expect(app(Credits::class)->balances($accountId)['available'])->toBe('20.000000000000000000');
    expect($legacy->fresh()->user_charge)->toBe('0.014031250000000000');
});

test('ledger rejects mutation and negative balance at the database boundary', function (string $operation) {
    $user = User::factory()->withCredits()->create();
    $entry = CreditEntry::sole();
    expect(fn () => DB::transaction(function () use ($entry, $operation) {
        if ($operation === 'update') {
            $entry->update(['amount' => '10']);
        } elseif ($operation === 'delete') {
            $entry->delete();
        } else {
            CreditEntry::create(['credit_account_id' => $entry->credit_account_id, 'request_key' => (string) Str::uuid(), 'kind' => 'debt_payment', 'amount' => '21', 'available_delta' => '-21', 'reserved_delta' => '0', 'outstanding_delta' => '-21']);
        }
    }))->toThrow(QueryException::class);
    expect(app(Credits::class)->forUser($user)['available'])->toBe('20.000000000000000000');
})->with(['update', 'delete', 'overspend']);

test('changing provider keys cannot silently move a reserved submission into another billing scope', function () {
    Queue::fake();
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    $generation->update(['provider_scope' => hash('sha256', 'original-test-key'), 'provider_metadata' => ['generation_id' => $generation->id, 'scope' => hash('sha256', 'original-test-key')]]);
    $parameters = $generation->fresh()->providerParameters();
    app()->call([new SyncGeneration($generation->id), 'handle']);
    Http::assertNothingSent();
    expect($generation->fresh()->error_code)->toBe('key_scope_changed');
    expect($generation->fresh()->next_sync_at)->toBeNull();
    expect(app(Credits::class)->forUser($user)['reserved'])->toBe('3.000000000000000000');
    config(['services.pixelforge.api_key' => 'original-test-key']);
    $this->travelTo($generation->fresh()->retry_not_before);
    $this->actingAs($user)->post(route('generations.retry', $generation))->assertRedirect(route('dashboard'));
    Http::fake(['*/v1/generations' => Http::response(pixelforgeSnapshot($generation, 'queued'), 202)]);
    app()->call([new SyncGeneration($generation->id), 'handle']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->data() === $parameters && $request->header('Idempotency-Key')[0] === $generation->request_key);
    expect(CreditEntry::where('kind', 'reserve')->count())->toBe(1);
});

test('a price increase consumes only unreserved credits before recording an outstanding amount', function () {
    $user = User::factory()->create();
    app(Credits::class)->purchase($user, '5', (string) Str::uuid());
    $generation = reservedGeneration($user, '1');
    $other = reservedGeneration($user, '3');
    $snapshot = pixelforgeSnapshot($generation);
    $snapshot['rates']['output_tokens'] = '10000.00';
    app(ApplyGeneration::class)->handle($snapshot);
    expect($generation->fresh()->user_charge)->toBe('3.500031250000000000');
    expect(app(Credits::class)->forUser($user))->toBe(['available' => '0.000000000000000000', 'reserved' => '3.000000000000000000', 'outstanding' => '1.500031250000000000']);
    app(ApplyGeneration::class)->handle(pixelforgeSnapshot($other, 'failed'));
    expect(app(Credits::class)->forUser($user)['available'])->toBe('1.499968750000000000');
    expect(app(Credits::class)->forUser($user)['outstanding'])->toBe('0.000000000000000000');
    expect(app(Credits::class)->canAfford($user, '1'))->toBeTrue();
    expect($other->fresh()->user_charge)->toBe('0.000000000000000000');
    expect(CreditEntry::where('generation_id', $other->id)->where('kind', 'debt_payment')->sole()->amount)->toBe('1.500031250000000000');
});

test('credit kinds are typed internally and serialize to stable public strings with private history', function () {
    $user = User::factory()->withCredits()->create();
    $other = User::factory()->create();
    app(Credits::class)->purchase($other, '5', (string) Str::uuid());
    $entry = CreditEntry::where('amount', '20')->sole();
    expect($entry->kind)->toBe(CreditEntryKind::Purchase);
    $this->actingAs($user)->get(route('credits.index', ['user_id' => $other->id]))->assertInertia(fn (Assert $page) => $page
        ->where('credits.available', '20.000000000000000000')->has('entries.data', 1)
        ->where('entries.data.0.kind', 'purchase')->where('entries.data.0.amount', '20.000000000000000000')
        ->missing('entries.data.0.request_key')->missing('entries.data.0.credit_account_id'));
});

test('financial POST endpoints enforce CSRF in normal web execution', function () {
    $user = User::factory()->create(['is_operator' => true]);
    $this->actingAs($user);
    $this->app->instance('env', 'local');
    try {
        $this->post(route('credits.store'), ['amount' => '5', 'purchase_key' => (string) Str::uuid()])->assertStatus(419);
        $this->post(route('reports.reconcile'), ['start' => '2026-11-18', 'end' => '2026-11-18'])->assertStatus(419);
    } finally {
        $this->app->instance('env', 'testing');
    }
    $this->assertDatabaseCount('credit_entries', 0);
    Http::assertNothingSent();
});

test('scheduled polling settles a reserved image once without any second create', function () {
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    $generation->update(['provider_id' => 'gen_reserved_poll', 'status' => 'processing']);
    Http::fake(['*/v1/generations/gen_reserved_poll' => Http::response(pixelforgeSnapshot($generation))]);
    $this->artisan('pixelforge:reconcile')->assertExitCode(0);
    $this->artisan('pixelforge:reconcile')->assertExitCode(0);
    expect(app(Credits::class)->forUser($user)['reserved'])->toBe('0.000000000000000000');
    expect(app(Credits::class)->forUser($user)['available'])->toBe('19.985968750000000000');
    expect(CreditEntry::where('kind', 'settle')->count())->toBe(1);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET');
});

test('a success webhook before the creation response settles the reservation despite a later HTTP failure', function () {
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    Http::fake(['*/v1/generations' => function () use ($generation) {
        sendPixelforgeEvent(generationEvent($generation, 'succeeded', 'evt_credit_early'))->assertNoContent();

        return Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '300']);
    }]);
    app()->call([new SyncGeneration($generation->id), 'handle']);
    expect($generation->fresh()->status)->toBe('succeeded');
    expect($generation->fresh()->retry_not_before)->toBeNull();
    expect(app(Credits::class)->forUser($user)['available'])->toBe('19.985968750000000000');
    expect(CreditEntry::where('kind', 'settle')->count())->toBe(1);
    expect(CreditEntry::where('kind', 'release')->count())->toBe(1);
    Http::assertSentCount(1);
});

test('nanodollar costs and fractional nanodollar markup survive ledger settlement without rounding', function () {
    $user = User::factory()->withCredits()->create();
    $generation = reservedGeneration($user);
    $snapshot = pixelforgeSnapshot($generation);
    $snapshot['rates']['input_text_tokens'] = '0.0002';
    $snapshot['rates']['output_tokens'] = '0.00';
    app(ApplyGeneration::class)->handle($snapshot);
    expect($generation->fresh()->provider_cost)->toBe('0.000000001000000000');
    expect(CreditEntry::where('kind', 'settle')->sole()->amount)->toBe('0.000000001250000000');
    expect(CreditEntry::where('kind', 'release')->sole()->amount)->toBe('2.999999998750000000');
    expect(app(Credits::class)->forUser($user)['available'])->toBe('19.999999998750000000');
});

test('the credit service rejects reservation against another users generation', function () {
    $user = User::factory()->withCredits()->create();
    $generation = Generation::factory()->create();
    expect(fn () => DB::transaction(fn () => app(Credits::class)->reserve($user, $generation)))->toThrow(LogicException::class);
    expect(app(Credits::class)->forUser($user)['available'])->toBe('20.000000000000000000');
    expect(CreditEntry::where('kind', 'reserve')->count())->toBe(0);
    expect($generation->fresh()->credit_account_id)->toBeNull();
});
