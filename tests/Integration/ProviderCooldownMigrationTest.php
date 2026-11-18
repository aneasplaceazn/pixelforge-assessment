<?php

use App\Models\Generation;
use App\Models\User;
use App\Services\Credits;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

test('durable cooldown migration preserves purchases reservations historical bills and pending recovery', function () {
    $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
    $user = User::factory()->withCredits()->create();
    $pending = reservedGeneration($user);
    $pending->update(['submission_uncertain' => true, 'sync_attempts' => 4, 'retry_not_before' => now()->addMinutes(5)]);
    $completed = Generation::factory()->for($user)->create(['status' => 'succeeded', 'provider_cost' => '0.016812500', 'user_charge' => '0.021015625', 'completed_at' => now(), 'next_sync_at' => null]);
    $before = [$user->refresh()->getAttributes(), $pending->refresh()->getAttributes(), $completed->refresh()->getAttributes(), app(Credits::class)->forUser($user), DB::table('credit_entries')->orderBy('id')->get()->toJson()];

    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

    expect([$user->refresh()->getAttributes(), $pending->refresh()->getAttributes(), $completed->refresh()->getAttributes(), app(Credits::class)->forUser($user), DB::table('credit_entries')->orderBy('id')->get()->toJson()])->toBe($before);
    $this->assertDatabaseCount('provider_cooldowns', 0);
    $this->assertDatabaseCount('generations', 2);
});
