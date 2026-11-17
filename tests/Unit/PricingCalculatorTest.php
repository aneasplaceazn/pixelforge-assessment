<?php

use App\Services\Pixelforge\PricingCalculator;

test('maximum quote matches the documented example including markup', function () {
    $pricing = json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);
    $quote = (new PricingCalculator)->estimate(str_repeat('a', 400), '1536x1024', 'high', 2, $pricing);

    expect($quote)->toBe(['provider_cost' => '0.373875000000000000', 'user_charge' => '0.467343750000000000']);
});

test('all size and quality combinations include maximum reasoning and text variance', function (string $size, string $quality, string $cost) {
    $pricing = json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);

    expect((new PricingCalculator)->estimate('🌅', $size, $quality, 0, $pricing)['provider_cost'])->toBe($cost);
})->with([
    ['1024x1024', 'low', '0.017460000000000000'],
    ['1536x1024', 'low', '0.023060000000000000'],
    ['1024x1536', 'low', '0.023060000000000000'],
    ['1024x1024', 'medium', '0.069010000000000000'],
    ['1536x1024', 'medium', '0.091010000000000000'],
    ['1024x1536', 'medium', '0.091010000000000000'],
    ['1024x1024', 'high', '0.272010000000000000'],
    ['1536x1024', 'high', '0.358010000000000000'],
    ['1024x1536', 'high', '0.358010000000000000'],
]);

test('fractional nanodollars survive markup without rounding', function () {
    $calculator = new PricingCalculator;
    $cost = $calculator->cost(array_fill_keys(PricingCalculator::TOKEN_TYPES, 1), array_fill_keys(PricingCalculator::TOKEN_TYPES, '0.000000001'));

    expect($cost)->toBe('0.000000000000004000');
    expect($calculator->charge($cost))->toBe('0.000000000000005000');
    expect($calculator->charge('0.000000001'))->toBe('0.000000001250000000');
});

test('actual usage uses supplied generation rates and all four token categories', function () {
    $calculator = new PricingCalculator;
    $cost = $calculator->cost(['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184], ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']);

    expect($cost)->toBe('0.305510000000000000');
    expect($calculator->charge($cost))->toBe('0.381887500000000000');
});

test('invalid usage and rates fail closed', function (mixed $tokens, mixed $rate) {
    expect(fn () => (new PricingCalculator)->cost(array_fill_keys(PricingCalculator::TOKEN_TYPES, $tokens), array_fill_keys(PricingCalculator::TOKEN_TYPES, $rate)))->toThrow(InvalidArgumentException::class);
})->with([[-1, '1.00'], [1.5, '1.00'], [1, 1.0], [1, '-1.00'], [1, '1e2'], [1, '0.0000000001'], [1000000001, '1.00']]);
