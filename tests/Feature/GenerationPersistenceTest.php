<?php

use App\Models\Generation;
use Illuminate\Database\QueryException;

test('settled fractional costs survive a database round trip', function () {
    $generation = Generation::factory()->create(['status' => 'succeeded', 'provider_cost' => '0.000000001', 'user_charge' => '0.00000000125']);

    expect($generation->fresh()->provider_cost)->toBe('0.000000001000000000');
    expect($generation->fresh()->user_charge)->toBe('0.000000001250000000');
});

test('the database refuses invalid billing states', function (array $attributes) {
    expect(fn () => Generation::factory()->create($attributes))->toThrow(QueryException::class);
})->with([
    [['status' => 'failed', 'provider_cost' => '1', 'user_charge' => '1.25']],
    [['status' => 'failed', 'provider_cost' => null, 'user_charge' => null]],
    [['status' => 'succeeded', 'provider_cost' => null, 'user_charge' => null]],
    [['status' => 'succeeded', 'provider_cost' => '1', 'user_charge' => '1.26']],
    [['status' => 'processing', 'provider_cost' => '1', 'user_charge' => '1.25']],
]);

test('a repeated request key cannot create a second billable record', function () {
    $generation = Generation::factory()->create();

    expect(fn () => Generation::factory()->create(['request_key' => $generation->request_key]))->toThrow(QueryException::class);
});
