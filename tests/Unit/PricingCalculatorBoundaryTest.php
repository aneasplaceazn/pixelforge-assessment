<?php

use App\Services\Pixelforge\PricingCalculator;

test('maximum length Unicode prompts and four references retain an exact upper bound', function () {
    $pricing = json_decode(file_get_contents(__DIR__.'/../Fixtures/pricing.json'), true);
    $quote = (new PricingCalculator)->estimate(str_repeat('🌅', 4000), '1536x1024', 'high', 4, $pricing);

    expect($quote['provider_cost'])->toBe('0.394350000000000000');
    expect($quote['user_charge'])->toBe('0.492937500000000000');
});

test('all zero usage is exactly free and missing categories cannot silently underbill', function () {
    $calculator = new PricingCalculator;
    $rates = array_fill_keys(PricingCalculator::TOKEN_TYPES, '1.00');

    expect($calculator->cost(array_fill_keys(PricingCalculator::TOKEN_TYPES, 0), $rates))->toBe('0.000000000000000000');
    expect(fn () => $calculator->cost([], $rates))->toThrow(InvalidArgumentException::class);
});
