<?php

use App\Actions\ApplyGeneration;
use App\Actions\ProcessWebhook;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('operators can reconcile deleted accounts without bypassing cooldowns or changing request keys', function () {
    $this->travelTo(now()->startOfSecond());
    Queue::fake();
    $generation = Generation::factory()->create(['submission_uncertain' => true, 'next_sync_at' => null, 'retry_not_before' => now()->addMinutes(5)]);
    $this->actingAs($generation->user)->delete(route('profile.destroy'), ['password' => 'password']);

    $this->artisan('pixelforge:resume', ['generation_id' => $generation->id])->assertExitCode(0);

    expect($generation->fresh()->next_sync_at->equalTo(now()->addMinutes(5)))->toBeTrue();
    expect($generation->fresh()->request_key)->toBe($generation->request_key);
    Queue::assertPushed(SyncGeneration::class);
    Http::assertNothingSent();
    $this->artisan('pixelforge:resume', ['generation_id' => $generation->id])->assertExitCode(1);
});

test('a definitive rejection after account deletion cancels free and erases content', function () {
    $generation = Generation::factory()->create();
    config(['services.pixelforge.api_key' => 'test-key']);
    Http::fake(['*/v1/generations' => function () use ($generation) {
        $this->actingAs($generation->user)->delete(route('profile.destroy'), ['password' => 'password']);

        return Http::response(['error' => ['code' => 'overloaded', 'message' => 'Try later']], 503, ['Retry-After' => '60']);
    }]);
    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));
    expect($generation->fresh()->status)->toBe('failed');
    expect($generation->fresh()->user_charge)->toBe('0.000000000000000000');
    expect($generation->fresh()->prompt)->toBe('');
    expect($generation->fresh()->next_sync_at)->toBeNull();
});

test('account deletion erases completed content but preserves exact billing records', function () {
    $generation = Generation::factory()->create();
    $snapshot = pixelforgeSnapshot($generation);
    app(ApplyGeneration::class)->handle($snapshot);
    $cost = $generation->fresh()->provider_cost;
    WebhookEvent::create(['id' => 'evt_private', 'type' => 'generation.succeeded', 'payload' => ['data' => ['object' => $snapshot]], 'processed_at' => now()]);
    DB::table('sessions')->insert(['id' => 'other-session', 'user_id' => $generation->user_id, 'payload' => 'private', 'last_activity' => time()]);

    $this->actingAs($generation->user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect(route('home'));

    expect($generation->fresh()->prompt)->toBe('');
    expect($generation->fresh()->reference_images)->toBe([]);
    expect($generation->fresh()->image_url)->toBeNull();
    expect($generation->fresh()->user_id)->toBeNull();
    expect($generation->fresh()->provider_cost)->toBe($cost);
    expect(WebhookEvent::findOrFail('evt_private')->payload)->toBe([]);
    $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
    app(ApplyGeneration::class)->handle($snapshot);
    expect($generation->fresh()->prompt)->toBe('');
});

test('account deletion cancels work that has never been submitted', function () {
    $generation = Generation::factory()->create();
    $this->actingAs($generation->user)->delete(route('profile.destroy'), ['password' => 'password']);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    Http::assertNothingSent();
    expect($generation->fresh()->status)->toBe('failed');
    expect($generation->fresh()->user_charge)->toBe('0.000000000000000000');
    expect($generation->fresh()->prompt)->toBe('');
});

test('deleted accounts retain uncertain parameters only until the provider result is settled', function (string $status) {
    $generation = Generation::factory()->create(['submission_uncertain' => true]);
    $snapshot = pixelforgeSnapshot($generation, $status);
    $parameters = $generation->providerParameters();
    config(['services.pixelforge.api_key' => 'test-key']);
    $this->actingAs($generation->user)->delete(route('profile.destroy'), ['password' => 'password']);
    expect($generation->fresh()->providerParameters())->toBe($parameters);
    Http::fake(['*/v1/generations' => Http::response($snapshot, 202)]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    Http::assertSent(fn ($request) => $request->data() === $parameters && $request->hasHeader('Idempotency-Key', $generation->request_key));
    expect($generation->fresh()->status)->toBe($status);
    expect($generation->fresh()->prompt)->toBe('');
    expect($generation->fresh()->image_url)->toBeNull();
    expect($generation->fresh()->user_charge)->not->toBeNull();
})->with(['succeeded', 'failed']);

test('deletion during an accepted request still records its final bill and erases content', function () {
    $generation = Generation::factory()->create();
    $snapshot = pixelforgeSnapshot($generation);
    config(['services.pixelforge.api_key' => 'test-key']);
    Http::fake(['*/v1/generations' => function () use ($generation, $snapshot) {
        $this->actingAs($generation->user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect();

        return Http::response($snapshot, 202);
    }]);

    (new SyncGeneration($generation->id))->handle(app(Client::class), app(ApplyGeneration::class));

    expect($generation->fresh()->status)->toBe('succeeded');
    expect($generation->fresh()->user_charge)->not->toBeNull();
    expect($generation->fresh()->prompt)->toBe('');
});

test('accepted generations settle from webhooks after deletion without keeping the event content', function () {
    $generation = Generation::factory()->create(['provider_id' => 'gen_deleted', 'status' => 'processing']);
    $snapshot = pixelforgeSnapshot($generation);
    $this->actingAs($generation->user)->delete(route('profile.destroy'), ['password' => 'password']);
    WebhookEvent::create(['id' => 'evt_after_deletion', 'type' => 'generation.succeeded', 'payload' => ['data' => ['object' => $snapshot]]]);

    app(ProcessWebhook::class)->handle('evt_after_deletion');

    expect($generation->fresh()->status)->toBe('succeeded');
    expect($generation->fresh()->prompt)->toBe('');
    expect(WebhookEvent::findOrFail('evt_after_deletion')->payload)->toBe([]);
});
