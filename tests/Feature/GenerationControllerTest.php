<?php

use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
});

function generationSettings(): array
{
    return ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];
}

function fakePricing(): void
{
    Http::fake(['*/v1/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true))]);
}

test('generation operations require authentication', function () {
    $this->post(route('generations.estimate'), generationSettings())->assertRedirect(route('login'));
    $this->post(route('generations.store'), generationSettings())->assertRedirect(route('login'));
    $this->assertDatabaseCount('generations', 0);
});

test('price must be reviewed and matches the exact settings and user', function () {
    fakePricing();
    Queue::fake([SyncGeneration::class]);
    $user = User::factory()->create();
    $this->actingAs($user);
    $quote = $this->postJson(route('generations.estimate'), generationSettings())->assertOk()->json('quote');

    $this->post(route('generations.store'), [...generationSettings(), 'quote' => $quote, 'quality' => 'high'])->assertSessionHasErrors('quote');
    $this->actingAs(User::factory()->create())->post(route('generations.store'), [...generationSettings(), 'quote' => $quote])->assertSessionHasErrors('quote');

    $this->assertDatabaseCount('generations', 0);
    Queue::assertNothingPushed();
});

test('confirmed requests survive duplicate submits without creating duplicate generations', function () {
    fakePricing();
    Queue::fake([SyncGeneration::class]);
    $this->actingAs(User::factory()->create());
    $quote = $this->postJson(route('generations.estimate'), generationSettings())->assertOk()->json('quote');
    $payload = [...generationSettings(), 'quote' => $quote, 'user_charge' => '999', 'status' => 'succeeded'];

    $this->post(route('generations.store'), $payload)->assertRedirect(route('dashboard'));
    $this->post(route('generations.store'), $payload)->assertRedirect(route('dashboard'));

    $this->assertDatabaseCount('generations', 1);
    $generation = Generation::firstOrFail();
    expect($generation->user_charge)->toBeNull();
    expect($generation->status)->toBe('queued');
    Queue::assertPushed(SyncGeneration::class, fn ($job) => $job->generationId === $generation->id);
});

test('invalid generation settings fail before contacting the provider', function (array $change, string $field) {
    $this->actingAs(User::factory()->create());

    $this->postJson(route('generations.estimate'), [...generationSettings(), ...$change])->assertUnprocessable()->assertJsonValidationErrors($field);

    Http::assertNothingSent();
})->with([
    [['prompt' => ''], 'prompt'], [['prompt' => str_repeat('x', 4001)], 'prompt'],
    [['size' => '512x512'], 'size'], [['quality' => 'ultra'], 'quality'],
    [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    [['reference_images' => ['file:///etc/passwd']], 'reference_images.0'],
    [['reference_images' => ['javascript:alert(1)']], 'reference_images.0'],
]);

test('history is private and excludes provider secrets and other users', function () {
    $own = Generation::factory()->create();
    $other = Generation::factory()->create();

    $this->actingAs($own->user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')->has('generations.data', 1)->where('generations.data.0.id', $own->id)
        ->missing('generations.data.0.pricing_snapshot')->missing('generations.data.0.provider_id'));
    $this->post(route('generations.retry', $other))->assertNotFound();
});

test('expired and tampered quotes are rejected', function () {
    fakePricing();
    $this->freezeTime();
    $this->actingAs(User::factory()->create());
    $quote = $this->postJson(route('generations.estimate'), generationSettings())->json('quote');
    $this->travel(6)->minutes();

    $this->post(route('generations.store'), [...generationSettings(), 'quote' => $quote])->assertSessionHasErrors('quote');
    $this->post(route('generations.store'), [...generationSettings(), 'quote' => $quote.'broken'])->assertSessionHasErrors('quote');
    $this->assertDatabaseCount('generations', 0);
});

test('safe manual retry keeps the original request key and respects active backoff', function () {
    Queue::fake([SyncGeneration::class]);
    $this->freezeTime();
    $generation = Generation::factory()->create(['next_sync_at' => null, 'sync_attempts' => 5, 'submission_uncertain' => true]);
    $this->actingAs($generation->user)->post(route('generations.retry', $generation))->assertRedirect(route('dashboard'));

    expect($generation->fresh()->request_key)->toBe($generation->request_key);
    expect($generation->fresh()->submission_uncertain)->toBeTrue();
    expect($generation->fresh()->sync_attempts)->toBe(0);
    $generation->update(['next_sync_at' => now()->addMinutes(2)]);
    $this->post(route('generations.retry', $generation));
    expect($generation->fresh()->next_sync_at->getTimestamp())->toBe(now()->addMinutes(2)->getTimestamp());
    Queue::assertPushed(SyncGeneration::class);
});

test('unavailable pricing fails closed without allowing submission', function () {
    Http::fake(['*/v1/pricing' => Http::response([], 503)]);

    $this->actingAs(User::factory()->create())->postJson(route('generations.estimate'), generationSettings())->assertServiceUnavailable();
    $this->assertDatabaseCount('generations', 0);
});

test('reference URLs survive price confirmation and provider submission unchanged', function () {
    fakePricing();
    config(['services.pixelforge.api_key' => 'test-key']);
    Http::fake(['*/v1/generations' => function ($request) {
        $generation = Generation::findOrFail($request['metadata']['generation_id']);

        return Http::response(pixelforgeSnapshot($generation, 'queued'), 202);
    }]);
    $settings = ['prompt' => str_repeat('🌅', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/one.png', 'http://example.com/two.png']];
    $this->actingAs(User::factory()->create());
    $quote = $this->postJson(route('generations.estimate'), $settings)->assertOk()->assertJsonPath('user_charge', '0.467343750000000000')->json('quote');

    $this->post(route('generations.store'), [...$settings, 'quote' => $quote])->assertRedirect(route('dashboard'));

    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['reference_images'] === $settings['reference_images'] && $request['prompt'] === $settings['prompt']);
    expect(Generation::firstOrFail()->reference_images)->toBe($settings['reference_images']);
});

test('maximum valid prompts and references complete price confirmation', function (string $prompt) {
    fakePricing();
    Queue::fake([SyncGeneration::class]);
    $this->actingAs(User::factory()->create());
    $url = 'https://example.com/'.str_repeat('a', 2048 - strlen('https://example.com/'));
    $settings = [...generationSettings(), 'prompt' => $prompt, 'reference_images' => array_fill(0, 4, $url)];
    $quote = $this->postJson(route('generations.estimate'), $settings)->assertOk()->json('quote');

    $this->postJson(route('generations.store'), [...$settings, 'quote' => $quote])->assertRedirect(route('dashboard'));

    expect(Generation::firstOrFail()->prompt)->toBe($prompt);
    expect(Generation::firstOrFail()->reference_images)->toBe($settings['reference_images']);
    Queue::assertPushed(SyncGeneration::class);
})->with([
    'CJK' => str_repeat('漢', 4000),
    'emoji' => str_repeat('🌅', 4000),
    'escaped characters' => str_repeat('"\\', 2000),
    'ASCII' => str_repeat('a', 4000),
]);

test('quote cache loss fails closed but an already saved request remains idempotent after expiry', function () {
    fakePricing();
    Queue::fake([SyncGeneration::class]);
    $this->actingAs(User::factory()->create());
    $lost = $this->postJson(route('generations.estimate'), generationSettings())->assertOk()->json('quote');
    Cache::forget('generation-quote:'.$lost);
    $this->postJson(route('generations.store'), [...generationSettings(), 'quote' => $lost])->assertUnprocessable()->assertJsonValidationErrors('quote');
    $this->assertDatabaseCount('generations', 0);
    $quote = $this->postJson(route('generations.estimate'), generationSettings())->json('quote');
    expect(strlen($quote))->toBe(36);
    $this->post(route('generations.store'), [...generationSettings(), 'quote' => $quote])->assertRedirect(route('dashboard'));
    $this->travel(6)->minutes();
    $this->post(route('generations.store'), [...generationSettings(), 'quote' => $quote])->assertRedirect(route('dashboard'));
    $this->assertDatabaseCount('generations', 1);
    $this->postJson(route('generations.store'), [...generationSettings(), 'quote' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('quote');
    Queue::assertPushed(SyncGeneration::class, 1);
});
