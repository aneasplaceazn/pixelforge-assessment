<?php

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\PricingCalculator;
use Illuminate\Support\Facades\Http;

test('contract-consistent usage settles through HTTP and stays within its unchanged maximum', function (string $size, string $quality) {
    config(['services.pixelforge.api_key' => 'test-key', 'services.pixelforge.webhook_secret' => 'whsec_test']);
    $pricing = json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);
    Http::fake([
        '*/v1/pricing' => Http::response($pricing),
        '*/v1/generations' => fn ($request) => Http::response(pixelforgeSnapshot(Generation::findOrFail($request['metadata']['generation_id']), 'queued'), 202),
    ]);
    $this->actingAs(User::factory()->create());
    $parameters = ['prompt' => str_repeat('😀', 4000), 'size' => $size, 'quality' => $quality, 'reference_images' => array_fill(0, 4, 'https://example.test/'.str_repeat('a', 2048 - strlen('https://example.test/')))];
    $quote = $this->postJson(route('generations.estimate'), $parameters)->assertOk()->json();
    $this->post(route('generations.store'), [...$parameters, 'quote' => $quote['quote']])->assertRedirect(route('dashboard'));
    $generation = Generation::sole();
    expect($generation->status)->toBe('queued');
    $snapshot = pixelforgeSnapshot($generation);
    expect($snapshot['usage']['input_image_tokens'])->toBe(3060);
    expect($snapshot['usage']['output_tokens'])->toBe($pricing['tokens']['output'][$quality][$size]);
    expect($snapshot['usage']['reasoning_tokens'])->toBeBetween($pricing['tokens']['reasoning'][$quality]['min'], $pricing['tokens']['reasoning'][$quality]['max']);
    $snapshot['usage']['input_text_tokens'] = 1150;
    $snapshot['usage']['reasoning_tokens'] = $pricing['tokens']['reasoning'][$quality]['max'];
    $snapshot['usage']['total_tokens'] = array_sum(array_intersect_key($snapshot['usage'], array_flip(PricingCalculator::TOKEN_TYPES)));
    $event = ['id' => 'evt_journey', 'object' => 'event', 'type' => 'generation.succeeded', 'data' => ['object' => $snapshot]];
    sendPixelforgeEvent($event)->assertNoContent();
    sendPixelforgeEvent($event)->assertNoContent();
    expect($generation->fresh()->status)->toBe('succeeded');
    expect(bccomp($generation->fresh()->provider_cost, $quote['provider_cost'], 18))->toBe(0);
    expect(bccomp($generation->fresh()->user_charge, $quote['user_charge'], 18))->toBe(0);
    expect($generation->fresh()->user_charge)->toBe(bcmul($generation->fresh()->provider_cost, '1.25', 18));
    expect(WebhookEvent::sole()->processed_at)->not->toBeNull();
    $this->get(route('dashboard'))->assertInertia(fn ($page) => $page->where('generations.data.0.status', 'succeeded')->where('generations.data.0.user_charge', $generation->fresh()->user_charge));
    Http::assertSentCount(2);
})->with(['1024x1024', '1536x1024', '1024x1536'])->with(['low', 'medium', 'high']);

test('lost webhooks are settled by scheduled retrieval without another create request', function () {
    config(['services.pixelforge.api_key' => 'test-key']);
    $generation = Generation::factory()->create(['provider_id' => 'gen_polling', 'status' => 'processing']);
    Http::fake(['*/v1/generations/gen_polling' => Http::response(pixelforgeSnapshot($generation))]);

    $this->artisan('pixelforge:reconcile')->assertExitCode(0);

    expect($generation->fresh()->status)->toBe('succeeded');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET');
    $this->artisan('pixelforge:reconcile')->assertExitCode(0);
    Http::assertSentCount(1);
});
