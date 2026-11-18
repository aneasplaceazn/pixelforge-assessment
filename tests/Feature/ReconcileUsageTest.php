<?php

use App\Models\Generation;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.pixelforge.api_key' => 'test-key']);
    Http::preventStrayRequests();
});

test('usage reconciles on UTC completion day preserving exact fractions', function () {
    Generation::factory()->create(['provider_scope' => hash('sha256', 'test-key'), 'status' => 'succeeded', 'created_at' => '2026-10-01 23:59:59', 'completed_at' => '2026-10-02 00:00:01', 'provider_cost' => '0.000000001', 'user_charge' => '0.00000000125']);
    Http::fake(['*/v1/usage*' => Http::response(['data' => [['date' => '2026-10-01', 'cost_usd' => '0.000000000'], ['date' => '2026-10-02', 'cost_usd' => '0.000000001']]])]);

    $this->artisan('pixelforge:usage 2026-10-01 2026-10-02')->assertSuccessful();
});

test('usage discrepancies fail the command without changing local records', function () {
    Http::fake(['*/v1/usage*' => Http::response(['data' => [['date' => '2026-10-02', 'cost_usd' => '1.000000000']]])]);

    $this->artisan('pixelforge:usage 2026-10-02 2026-10-02')->assertFailed();
    $this->assertDatabaseCount('generations', 0);
});

test('an incomplete usage response cannot produce a false match', function () {
    Http::fake(['*/v1/usage*' => Http::response(['data' => [['date' => '2026-10-01', 'cost_usd' => '0.000000000']]])]);

    $this->artisan('pixelforge:usage 2026-10-01 2026-10-02')->assertFailed();
});

test('usage command refuses unverified legacy scope and excludes other keys', function () {
    $legacy = Generation::factory()->create(['status' => 'succeeded', 'completed_at' => '2026-10-02 00:00:01', 'provider_cost' => '0.000000001', 'user_charge' => '0.00000000125']);
    $this->artisan('pixelforge:usage 2026-10-02 2026-10-02')->expectsOutputToContain('UNVERIFIED')->assertFailed();
    Http::assertNothingSent();
    $legacy->update(['provider_scope' => hash('sha256', 'other-key')]);
    Http::fake(['*/v1/usage*' => Http::response(['data' => [['date' => '2026-10-02', 'cost_usd' => '0.000000000']]])]);
    $this->artisan('pixelforge:usage 2026-10-02 2026-10-02')->assertSuccessful();
    expect($legacy->fresh()->provider_cost)->toBe('0.000000001000000000');
});
