<?php

use App\Actions\ApplyGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.pixelforge.api_key' => 'test-key', 'services.pixelforge.webhook_secret' => 'whsec_test']);
    Route::get('/testing/image.png', function () {
        $image = imagecreatetruecolor(1024, 1024);
        ob_start();
        imagepng($image);

        return response(ob_get_clean(), 200, ['Content-Type' => 'image/png']);
    });
});

test('registration through final billing works in the browser', function () {
    Http::fake([
        '*/v1/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true)),
        '*/v1/generations' => function ($request) {
            $generation = Generation::findOrFail($request['metadata']['generation_id']);

            return Http::response(pixelforgeSnapshot($generation, 'queued'), 202);
        },
    ]);
    $page = visit('/register')->fill('name', 'Studio Visitor')->fill('email', 'browser@example.test')
        ->fill('password', 'a-safe-test-password')->fill('password_confirmation', 'a-safe-test-password')->press('Create account')
        ->assertSee('A thought. An image.')->click('Buy credits')->press('Add simulated credits')->assertSee('Credits added.')
        ->click('Back to image studio')->fill('#prompt', 'A lighthouse')->select('#quality', 'low')
        ->press('Get price estimate')->assertSee('Maximum estimated price')->assertSee('$0.0218375')
        ->press('Generate image')->assertSee('Waiting to generate')->assertSee('Pending');
    $generation = Generation::firstOrFail();
    sendPixelforgeEvent(generationEvent($generation, 'processing', 'evt_browser_processing'))->assertNoContent();
    $page->assertSee('Creating your image…');
    $completed = pixelforgeSnapshot($generation);
    $completed['image_url'] = $page->script('location.origin').'/testing/image.png';
    sendPixelforgeEvent(['id' => 'evt_browser_success', 'object' => 'event', 'type' => 'generation.succeeded', 'data' => ['object' => $completed]])->assertNoContent();
    $page->assertSee('$0.01401875')->assertSee('succeeded')->assertVisible('[data-generation-id] img')
        ->assertScript('document.querySelector("[data-generation-id] img").complete && document.querySelector("[data-generation-id] img").naturalWidth === 1024')
        ->assertNoJavascriptErrors()->screenshot(filename: 'image-studio-desktop');
    $page->refresh()->assertSee('$0.01401875')->assertSee('succeeded')->assertSee('$4.98598125')
        ->assertScript('document.querySelector("[data-generation-id] img").complete && document.querySelector("[data-generation-id] img").naturalWidth === 1024')
        ->assertNoJavascriptErrors();
    expect($generation->fresh()->user_charge)->toBe('0.014018750000000000');
    expect(bccomp($generation->fresh()->user_charge, $generation->estimated_charge, 18))->toBeLessThanOrEqual(0);
});

test('mobile form validates URLs and failed images clearly show zero charge', function () {
    $generation = Generation::factory()->create(['status' => 'failed', 'provider_cost' => '0', 'user_charge' => '0', 'error_message' => 'The generation failed. You were not charged.']);
    $this->actingAs($generation->user);
    $page = visit('/dashboard')->on()->mobile()->assertSee('Generation unsuccessful')->assertSee('$0.00')
        ->fill('#prompt', 'A coast')->fill('#references', 'not-a-url')->press('Get price estimate')->assertSee('Please check the highlighted settings.');
    $page->assertAttribute('#references', 'aria-invalid', 'true')->assertAttribute('#references', 'aria-describedby', 'reference-help reference-errors')
        ->assertNoJavascriptErrors()->assertNoAccessibilityIssues()->screenshot(filename: 'image-studio-mobile');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    Http::assertNothingSent();
});

test('paused requests explain that checks stopped and remain keyboard recoverable', function () {
    $generation = Generation::factory()->create(['next_sync_at' => null, 'sync_attempts' => 5, 'error_message' => 'Outdated misleading message']);
    $this->actingAs($generation->user);
    Http::fake(['*/v1/generations' => Http::response(pixelforgeSnapshot($generation, 'failed'), 202)]);
    visit('/dashboard')->assertSee('Automatic checks are paused.')
        ->assertDontSee('We’ll keep checking.')->assertDontSee('Outdated misleading message')
        ->keys('Check saved request again', 'Enter')->assertSee('Generation unsuccessful')
        ->keys('Use prompt again', 'Enter')->assertScript('document.activeElement.id', 'prompt')->assertNoJavascriptErrors();
});

test('temporary image failures can retry without being mislabeled as expired', function () {
    $generation = Generation::factory()->create();
    $this->actingAs($generation->user);
    $page = visit('/dashboard');
    $snapshot = pixelforgeSnapshot($generation);
    $snapshot['image_url'] = $page->script('location.origin').'/testing/retry.png';
    $available = false;
    Route::get('/testing/retry.png', function () use (&$available) {
        if (! $available) {
            return response('', 503, ['Cache-Control' => 'no-store']);
        }
        $image = imagecreatetruecolor(1024, 1024);
        ob_start();
        imagepng($image);

        return response(ob_get_clean(), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
    });
    app(ApplyGeneration::class)->handle($snapshot);
    $page->assertSee('Image could not load')->assertDontSee('Image link expired');
    $available = true;
    $page->keys('Retry image', 'Enter')->assertVisible('[data-generation-id] img')
        ->assertScript('document.querySelector("[data-generation-id] img").naturalWidth === 1024')->assertNoJavascriptErrors();
});

test('expired images explain expiry and offer a new generation', function () {
    $generation = Generation::factory()->create();
    $snapshot = pixelforgeSnapshot($generation);
    $snapshot['image_url_expires_at'] = now()->subMinute()->toIso8601String();
    app(ApplyGeneration::class)->handle($snapshot);
    $this->actingAs($generation->user);
    visit('/dashboard')->assertSee('Image link expired')->assertDontSee('Retry image')
        ->keys('Use prompt again', 'Enter')->assertScript('document.activeElement.id', 'prompt')->assertNoJavascriptErrors();
});

test('the browser counts emoji as Unicode characters through the maximum prompt', function () {
    $this->actingAs(User::factory()->create());
    Http::fake(['*/v1/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true))]);
    $page = visit('/dashboard')->fill('#prompt', str_repeat('😀', 4000))->assertSee('4000 / 4,000 characters.')
        ->press('Get price estimate')->assertSee('Maximum estimated price')->assertNoJavascriptErrors();
    $page->fill('#prompt', str_repeat('😀', 4001))->assertSee('4001 / 4,000 characters.')->assertDisabled('Get price estimate');
});

test('saved requests expose a safe retry and prompt reuse requires a new estimate', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create(['next_sync_at' => null, 'sync_attempts' => 5]);
    Http::fake(['*/v1/generations' => Http::response(pixelforgeSnapshot($generation, 'failed'), 202)]);
    $this->actingAs($user);

    visit('/dashboard')->press('Check saved request again')->assertSee('Generation unsuccessful')
        ->press('Use prompt again')->assertSee('Get price estimate')->assertNoJavascriptErrors();

    expect($generation->fresh()->request_key)->toBe($generation->request_key);
    $this->assertDatabaseCount('generations', 1);
});
