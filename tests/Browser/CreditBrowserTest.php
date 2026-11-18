<?php

use App\Actions\ApplyGeneration;
use App\Models\CreditEntry;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('mobile users buy fake credits with a keyboard and see the exact updated balance and history', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $page = visit('/credits')->on()->mobile()->assertSee('No real payment is collected.')
        ->assertSee('No transactions yet.')->select('#credit-amount', '10')->keys('Add simulated credits', 'Enter')
        ->assertSee('Credits added. No real payment was made.')->assertSee('Simulated purchase')
        ->assertScript('document.querySelector("[data-credit-available]").textContent.trim()', '$10.00')
        ->assertNoAccessibilityIssues()->assertNoJavascriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->click('Back to image studio')->assertSee('Available:')->assertSee('$10.00')->assertDontSee('Financial reports');
    expect(CreditEntry::where('kind', 'purchase')->count())->toBe(1);
    Http::assertNothingSent();
});

test('an operator filters exact financial reports and sees a provider reconciliation snapshot', function () {
    config(['services.pixelforge.api_key' => 'test-key']);
    $operator = User::factory()->create(['is_operator' => true]);
    $generation = Generation::factory()->create(['provider_scope' => hash('sha256', 'test-key')]);
    $generation->update(['provider_metadata' => ['generation_id' => $generation->id, 'scope' => $generation->provider_scope]]);
    $snapshot = pixelforgeSnapshot($generation);
    $snapshot['completed_at'] = '2026-11-18T12:00:00Z';
    app(ApplyGeneration::class)->handle($snapshot);
    $totals = ['generations' => 1, 'cost_usd' => '0.011225000', 'input_text_tokens' => 5, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 0];
    Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'start_date' => '2026-11-18', 'end_date' => '2026-11-18', 'data' => [['date' => '2026-11-18', ...$totals]], 'total' => $totals]),
        '*/v1/generations*' => Http::response(['object' => 'list', 'data' => [$snapshot], 'has_more' => false, 'next_cursor' => null])]);
    $this->actingAs($operator);
    $page = visit('/operator/finances')->fill('#start', '2026-11-18')->fill('#end', '2026-11-18')->keys('Apply dates', 'Enter')
        ->assertSee('$0.01403125')->assertSee('$0.011225')->assertSee('$0.00280625')
        ->assertSee('Showing 2026-11-18 to 2026-11-18 (UTC)')->keys('Compare with PixelForge', 'Enter')->assertSee('Comparison: reconciled')->assertSee('Generation inventory: reconciled')
        ->assertNoAccessibilityIssues()->assertNoJavascriptErrors();
    $page = visit('/operator/finances?start=2026-11-18&end=2026-11-18')->on()->mobile()->assertSee('By user')->assertNoAccessibilityIssues()->assertNoJavascriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    Http::assertSentCount(2);
});

test('operator sees unknown legacy work separately from equal completed costs', function () {
    config(['services.pixelforge.api_key' => 'test-key']);
    $this->actingAs(User::factory()->create(['is_operator' => true]));
    Generation::factory()->create(['provider_scope' => null, 'submission_uncertain' => true, 'next_sync_at' => null, 'created_at' => '2026-11-17T23:59:59Z']);
    $totals = ['generations' => 0, 'cost_usd' => '0.000000000'];
    Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'start_date' => '2026-11-18', 'end_date' => '2026-11-18', 'data' => [['date' => '2026-11-18', ...$totals]], 'total' => $totals]),
        '*/v1/generations*' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null])]);

    visit('/operator/finances?start=2026-11-18&end=2026-11-18')->keys('Compare with PixelForge', 'Enter')
        ->assertSee('Comparison: unverified')->assertSee('Generation inventory: unverified')
        ->assertSee('1 with unknown scope')->assertSee('Matching completed totals do not prove completeness.')
        ->assertSee('reconciled')->assertNoJavascriptErrors();

    Http::assertSentCount(2);
});
