<?php

namespace App\Services\Pixelforge;

use InvalidArgumentException;

class PricingCalculator
{
    public const SCALE = 18;

    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * Decimal strings throughout: at most 9 rate decimals + 6 for per-million
     * conversion + 2 for the markup fit exactly within 18 decimal places.
     *
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates
     * @return numeric-string
     */
    public function cost(array $usage, array $rates): string
    {
        $total = '0';
        foreach (self::TOKEN_TYPES as $type) {
            $tokens = $usage[$type] ?? null;
            $rate = $rates[$type] ?? null;
            if (! is_int($tokens) || $tokens < 0 || $tokens > 1000000000 || ! is_string($rate) || ! is_numeric($rate)
                || ! preg_match('/\A\d{1,6}(?:\.\d{1,9})?\z/', $rate)) {
                throw new InvalidArgumentException('Invalid provider usage or rate.');
            }
            $total = bcadd($total, bcdiv(bcmul((string) $tokens, $rate, self::SCALE), '1000000', self::SCALE), self::SCALE);
        }

        return $total;
    }

    /** @return numeric-string */
    public function charge(string $providerCost): string
    {
        if (! is_numeric($providerCost)) {
            throw new InvalidArgumentException('Invalid provider cost.');
        }

        return bcmul($providerCost, '1.25', self::SCALE);
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array{provider_cost: string, user_charge: string}
     */
    public function estimate(string $prompt, string $size, string $quality, int $references, array $pricing): array
    {
        $tokens = $pricing['tokens'];
        $charactersPerToken = $tokens['characters_per_text_token'];
        if (! is_int($charactersPerToken) || $charactersPerToken < 1 || $references < 0 || $references > 4) {
            throw new InvalidArgumentException('Invalid pricing parameters.');
        }
        $base = intdiv(mb_strlen($prompt) + $charactersPerToken - 1, $charactersPerToken);
        $variance = (string) $tokens['text_token_variance'];
        if (! is_numeric($variance) || bccomp($variance, '0', self::SCALE) < 0 || bccomp($variance, '1', self::SCALE) > 0) {
            throw new InvalidArgumentException('Invalid text token variance.');
        }
        $upperText = bcmul((string) $base, bcadd('1', $variance, self::SCALE), self::SCALE);
        $textTokens = (int) $upperText;
        if (bccomp($upperText, (string) $textTokens, self::SCALE) > 0) {
            $textTokens++;
        }
        $cost = $this->cost([
            'input_text_tokens' => $textTokens,
            'input_image_tokens' => $references * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$quality][$size],
            'reasoning_tokens' => $tokens['reasoning'][$quality]['max'],
        ], $pricing['rates']);

        return ['provider_cost' => $cost, 'user_charge' => $this->charge($cost)];
    }
}
