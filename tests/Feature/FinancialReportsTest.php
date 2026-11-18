<?php

use App\Actions\ApplyGeneration;
use App\Actions\DeleteAccount;
use App\Models\Generation;
use App\Models\User;
use App\Services\FinancialReports;
use App\Services\Pixelforge\ProviderCooldown;
use App\Services\UsageReconciliation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.pixelforge.api_key' => 'test-key', 'services.pixelforge.webhook_secret' => 'whsec_test']);
});

function financialOperator(): User
{
    return User::factory()->create(['is_operator' => true]);
}

function usageResponse(string $date, string $cost, int $count): array
{
    $row = ['generations' => $count, 'cost_usd' => $cost, 'input_text_tokens' => 5 * $count, 'input_image_tokens' => 0, 'output_tokens' => 280 * $count, 'reasoning_tokens' => 0];

    return ['object' => 'usage', 'start_date' => $date, 'end_date' => $date, 'data' => [['date' => $date, ...$row]], 'total' => $row];
}

function scopedCompletion(string $date = '2026-11-18'): Generation
{
    $generation = Generation::factory()->create(['provider_scope' => hash('sha256', 'test-key')]);
    $generation->update(['provider_metadata' => ['generation_id' => $generation->id, 'scope' => $generation->provider_scope]]);
    $snapshot = pixelforgeSnapshot($generation);
    $snapshot['completed_at'] = $date.'T12:00:00Z';
    app(ApplyGeneration::class)->handle($snapshot);

    return $generation->refresh();
}

function fakeUsageAndInventory(array $usage, array $generations): void
{
    Http::fake(['*/v1/usage*' => Http::response($usage), '*/v1/generations*' => Http::response(['object' => 'list', 'data' => $generations, 'has_more' => false, 'next_cursor' => null])]);
}

test('public registration and profile edits cannot grant operator privileges or expose financial data', function () {
    $this->post(route('register'), ['name' => 'Visitor', 'email' => 'operator-test@example.test', 'password' => 'Strong-test-password1!', 'password_confirmation' => 'Strong-test-password1!', 'is_operator' => true])->assertRedirect();
    $user = User::sole();
    expect($user->is_operator)->toBeFalse();
    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Updated visitor', 'email' => $user->email, 'is_operator' => true])->assertRedirect(route('profile.edit'));
    expect($user->refresh()->name)->toBe('Updated visitor');
    expect($user->is_operator)->toBeFalse();
    expect($user->toArray())->not->toHaveKey('is_operator');
    $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
    $this->post(route('reports.reconcile'))->assertForbidden();
    expect(fn () => app(FinancialReports::class)->build($user, '2026-11-18', '2026-11-18'))->toThrow(AuthorizationException::class);
    expect(fn () => app(UsageReconciliation::class)->compare($user, '2026-11-18', '2026-11-18'))->toThrow(AuthorizationException::class);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('can_report', false)->missing('report')->missing('auth.user.is_operator'));
    Http::assertNothingSent();
});

test('operator privileges require an explicit CLI grant to an existing user and can be revoked', function () {
    $user = User::factory()->create();
    $this->artisan('operator:access', ['user_id' => $user->id])->assertExitCode(0);
    expect($user->refresh()->is_operator)->toBeTrue();
    $this->actingAs($user)->get(route('reports.index'))->assertOk();
    $this->artisan('operator:access', ['user_id' => $user->id, '--revoke' => true])->assertExitCode(0);
    $this->actingAs($user->refresh())->get(route('reports.index'))->assertForbidden();
    $this->artisan('operator:access', ['user_id' => '999999999'])->assertExitCode(1);
    $this->assertDatabaseCount('users', 1);
});

test('financial reports use UTC completion dates exact costs and retained anonymous records', function () {
    $this->travelTo('2026-11-20T12:00:00Z');
    $operator = financialOperator();
    $first = scopedCompletion('2026-11-18');
    $first->update(['created_at' => '2026-11-17 23:59:59']);
    $second = scopedCompletion('2026-11-19');
    $failed = Generation::factory()->for($first->user)->create();
    $snapshot = pixelforgeSnapshot($failed, 'failed');
    $snapshot['completed_at'] = '2026-11-18T18:00:00Z';
    app(ApplyGeneration::class)->handle($snapshot);
    app(DeleteAccount::class)->handle($second->user);
    sendPixelforgeEvent(generationEvent($first, 'succeeded', 'evt_reports_duplicate'))->assertNoContent();
    sendPixelforgeEvent(generationEvent($first, 'processing', 'evt_reports_late'))->assertNoContent();
    $report = app(FinancialReports::class)->build($operator, '2026-11-18', '2026-11-19');
    expect($report['totals']->charges)->toBe('0.028062500000000000');
    expect($report['totals']->provider_cost)->toBe('0.022450000000000000');
    expect($report['totals']->profit)->toBe('0.005612500000000000');
    expect($report['totals']->total)->toBe(3);
    expect($report['totals']->failed)->toBe(1);
    expect($report['days']->pluck('day')->all())->toBe(['2026-11-18', '2026-11-19']);
    expect($report['users']->total())->toBe(2);
    expect($report['users']->getCollection()->whereNull('name')->count())->toBe(1);
    expect(app(FinancialReports::class)->build($operator, '2026-11-17', '2026-11-17')['totals']->total)->toBe(0);
    $this->actingAs($operator)->get(route('reports.index', ['start' => '2026-11-19', 'end' => '2026-11-18']))->assertSessionHasErrors('end');
    $this->get(route('reports.index', ['start' => '2026-01-01', 'end' => '2026-11-18']))->assertSessionHasErrors('end');
});

test('usage reconciles exact daily and overall provider costs with generation specific rates', function () {
    $this->travelTo('2026-11-18T12:00:00Z');
    $generation = scopedCompletion();
    fakeUsageAndInventory(usageResponse('2026-11-18', '0.011225000', 1), [pixelforgeSnapshot($generation)]);
    $result = app(UsageReconciliation::class)->compare(financialOperator(), '2026-11-18', '2026-11-18');
    expect($result['state'])->toBe('reconciled');
    expect($result['inventory_state'])->toBe('reconciled');
    expect($result['days'][0]['local_cost'])->toBe('0.011225000000000000');
    expect($result['totals']['provider_cost'])->toBe('0.011225000');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), 'start_date=2026-11-18'));
});

test('reconciliation identifies provider only missing provider unsettled and cost mismatches without changing money', function () {
    $this->travelTo('2026-11-18T12:00:00Z');
    $missingProvider = scopedCompletion();
    $mismatch = scopedCompletion();
    $altered = pixelforgeSnapshot($mismatch);
    $altered['rates']['output_tokens'] = '80.00';
    $pending = Generation::factory()->create(['provider_scope' => hash('sha256', 'test-key')]);
    $pending->update(['provider_metadata' => ['generation_id' => $pending->id, 'scope' => $pending->provider_scope]]);
    $providerOnly = pixelforgeSnapshot($mismatch);
    $providerOnly['id'] = 'gen_only_provider';
    $providerOnly['metadata']['generation_id'] = (string) Str::uuid();
    fakeUsageAndInventory(usageResponse('2026-11-18', '0.099000001', 3), [$providerOnly, $altered, pixelforgeSnapshot($pending)]);
    $result = app(UsageReconciliation::class)->compare(financialOperator(), '2026-11-18', '2026-11-18');
    expect($result['state'])->toBe('mismatched');
    expect($result['missing_local'])->toBe(['gen_only_provider']);
    expect($result['missing_provider'])->toBe([$missingProvider->provider_id]);
    expect($result['unsettled'])->toBe(['gen_'.$pending->id]);
    expect($result['mismatched'])->toBe([$mismatch->provider_id]);
    expect($mismatch->fresh()->provider_cost)->toBe('0.011225000000000000');
    expect($pending->fresh()->user_charge)->toBeNull();
});

test('key scoped usage never mixes another key and legacy scope requires explicit operator assertion', function () {
    $this->travelTo('2026-11-18T12:00:00Z');
    $legacy = Generation::factory()->create();
    app(ApplyGeneration::class)->handle(pixelforgeSnapshot($legacy));
    $otherKey = scopedCompletion();
    $otherKey->update(['provider_scope' => hash('sha256', 'other-key'), 'provider_metadata' => ['generation_id' => $otherKey->id, 'scope' => hash('sha256', 'other-key')]]);
    fakeUsageAndInventory(usageResponse('2026-11-18', '0.011225000', 1), [pixelforgeSnapshot($legacy), pixelforgeSnapshot($otherKey)]);
    $operator = financialOperator();
    $result = app(UsageReconciliation::class)->compare($operator, '2026-11-18', '2026-11-18');
    expect($result['state'])->toBe('unverified');
    expect($result['legacy_scope_count'])->toBe(1);
    expect($result['inventory_state'])->toBe('unverified');
    $parameters = $legacy->providerParameters();
    $this->artisan('pixelforge:adopt-scope')->assertExitCode(0);
    expect($legacy->fresh()->provider_scope)->toBeNull();
    $this->artisan('pixelforge:adopt-scope', ['--confirm-dedicated-key' => true])->assertExitCode(0);
    expect($legacy->fresh()->providerParameters())->toBe($parameters);
    expect($otherKey->fresh()->provider_scope)->toBe(hash('sha256', 'other-key'));
    $result = app(UsageReconciliation::class)->compare($operator, '2026-11-18', '2026-11-18');
    expect($result['state'])->toBe('reconciled');
    expect($result['inventory_state'])->toBe('reconciled');
});

test('provider errors malformed totals and pagination limits remain explicitly unverified', function (string $case) {
    $operator = financialOperator();
    if ($case === 'offline') {
        Http::fake(['*' => Http::failedConnection()]);
    } elseif ($case === 'totals') {
        $usage = usageResponse('2026-11-18', '0.000000000', 0);
        $usage['total']['cost_usd'] = '0.000000001';
        fakeUsageAndInventory($usage, []);
    } else {
        $calls = 0;
        Http::fake(['*/v1/usage*' => Http::response(usageResponse('2026-11-18', '0.000000000', 0)), '*/v1/generations*' => function () use (&$calls) {
            return Http::response(['object' => 'list', 'data' => [], 'has_more' => true, 'next_cursor' => 'page'.(++$calls)]);
        }]);
    }
    $result = app(UsageReconciliation::class)->compare($operator, '2026-11-18', '2026-11-18');
    if ($case === 'pages') {
        expect($result['inventory_state'])->toBe('unverified');
        Http::assertSentCount(11);
    } else {
        expect($result['state'])->toBe('unverified');
        expect($result['message'])->not->toBeNull();
    }
})->with(['offline', 'totals', 'pages']);

test('report retries respect a fractional provider cooldown without automatic HTTP retries', function () {
    $this->travelTo('2026-11-18T12:00:00.500Z');
    $operator = financialOperator();
    Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '120'])]);
    $service = app(UsageReconciliation::class);
    expect($service->compare($operator, '2026-11-18', '2026-11-18')['state'])->toBe('unverified');
    Http::assertSentCount(1);
    $this->travelTo('2026-11-18T12:02:00.999Z');
    expect($service->compare($operator, '2026-11-18', '2026-11-18')['message'])->toContain('paused until');
    Http::assertSentCount(1);
    $this->travelTo('2026-11-18T12:02:01.000Z');
    $service->compare($operator, '2026-11-18', '2026-11-18');
    Http::assertSentCount(2);
});

test('deleting two legacy owners keeps their retained financial reports separate', function () {
    $this->travelTo('2026-11-18T12:00:00Z');
    $first = scopedCompletion();
    $second = scopedCompletion();
    app(DeleteAccount::class)->handle($first->user);
    app(DeleteAccount::class)->handle($second->user);
    $report = app(FinancialReports::class)->build(financialOperator(), '2026-11-18', '2026-11-18');
    expect($report['users']->total())->toBe(2);
    expect($report['users']->getCollection()->pluck('name')->all())->toBe([null, null]);
    expect($report['users']->getCollection()->pluck('charges')->all())->toBe(['0.014031250000000000', '0.014031250000000000']);
});

test('an adopted legacy pending record is recognized without changing its original metadata', function () {
    $generation = Generation::factory()->create(['provider_scope' => hash('sha256', 'test-key')]);
    fakeUsageAndInventory(usageResponse(now('UTC')->toDateString(), '0.000000000', 0), [pixelforgeSnapshot($generation, 'processing')]);
    $result = app(UsageReconciliation::class)->compare(financialOperator(), now('UTC')->toDateString(), now('UTC')->toDateString());
    expect($result['state'])->toBe('pending');
    expect($result['inventory_state'])->toBe('pending');
    expect($result['unsettled'])->toBe(['gen_'.$generation->id]);
    expect($generation->fresh()->providerParameters()['metadata'])->toBe(['generation_id' => $generation->id]);
});

test('a later comparison cooldown recorded during an in flight request cannot be shortened', function () {
    $this->travelTo('2026-11-18T12:00:00.500Z');
    $operator = financialOperator();
    Http::fake(['*' => function () {
        app(ProviderCooldown::class)->extend(300);

        return Http::response(['error' => ['code' => 'rate_limit_exceeded']], 429, ['Retry-After' => '2']);
    }]);
    $service = app(UsageReconciliation::class);
    expect($service->compare($operator, '2026-11-18', '2026-11-18')['message'])->toContain('12:05:01');
    $this->travelTo('2026-11-18T12:01:00Z');
    expect($service->compare($operator, '2026-11-18', '2026-11-18')['message'])->toContain('paused until');
    Http::assertSentCount(1);
});

test('unknown scope unfinished legacy work prevents a complete reconciliation even when completed totals match', function () {
    $this->travelTo('2026-11-18T12:00:00Z');
    $legacy = Generation::factory()->create(['provider_scope' => null, 'submission_uncertain' => true, 'next_sync_at' => null, 'created_at' => '2026-11-17T23:59:59Z']);
    fakeUsageAndInventory(usageResponse('2026-11-18', '0.000000000', 0), []);

    $result = app(UsageReconciliation::class)->compare(financialOperator(), '2026-11-18', '2026-11-18');

    expect($result['state'])->toBe('unverified');
    expect($result['inventory_state'])->toBe('unverified');
    expect($result['legacy_pending_count'])->toBe(1);
    expect($result['legacy_scope_count'])->toBe(1);
    expect($result['pending_count'])->toBe(1);
    expect($result['days'][0]['state'])->toBe('reconciled');
    expect($result['message'])->toContain('unknown scope');
    expect($legacy->refresh()->provider_scope)->toBeNull();
    expect($legacy->user_charge)->toBeNull();
});

test('unknown pending scope respects the reporting end boundary without guessing another keys work', function (string $created, ?string $scope, string $state, int $pending) {
    $this->travelTo('2026-11-18T12:00:00Z');
    Generation::factory()->create(['status' => 'processing', 'provider_scope' => $scope, 'created_at' => $created]);
    fakeUsageAndInventory(usageResponse('2026-11-18', '0.000000000', 0), []);

    $result = app(UsageReconciliation::class)->compare(financialOperator(), '2026-11-18', '2026-11-18');

    expect($result['state'])->toBe($state);
    expect($result['inventory_state'])->toBe($state);
    expect($result['pending_count'])->toBe($pending);
    expect($result['days'][0]['state'])->toBe('reconciled');
})->with([
    'older unknown work remains uncertain' => ['2026-11-17T23:59:59Z', null, 'unverified', 1],
    'last second of range remains uncertain' => ['2026-11-18T23:59:59Z', null, 'unverified', 1],
    'next day is outside range' => ['2026-11-19T00:00:00Z', null, 'reconciled', 0],
    'known other key is excluded' => ['2026-11-18T12:00:00Z', hash('sha256', 'other-key'), 'reconciled', 0],
    'known current key is pending even with an empty inventory' => ['2026-11-18T12:00:00Z', hash('sha256', 'test-key'), 'pending', 1],
]);

test('a pre correction comparison snapshot cannot hide unknown legacy work after upgrade', function () {
    $operator = financialOperator();
    Cache::put('financial-reconciliation:'.$operator->id.':2026-11-18:2026-11-18:'.hash('sha256', 'test-key'), ['state' => 'reconciled', 'inventory_state' => 'reconciled'], 300);

    $this->actingAs($operator)->get(route('reports.index', ['start' => '2026-11-18', 'end' => '2026-11-18']))
        ->assertInertia(fn (Assert $page) => $page->where('reconciliation', null));

    Http::assertNothingSent();
});
