<?php

namespace App\Services\Pixelforge;

use RuntimeException;

class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $providerCode,
        public readonly bool $retryable = false,
        public readonly int $retryAfter = 0,
        public readonly bool $uncertain = false,
    ) {
        parent::__construct(match ($providerCode) {
            'invalid_api_key', 'not_configured' => 'The image provider is not configured correctly. Please contact support.',
            'content_policy_violation' => 'The provider rejected this prompt. Please change it and try again. You were not charged.',
            'validation_failed' => 'The provider rejected the generation settings. Please review them.',
            'daily_limit_exceeded' => 'The provider’s daily quota is exhausted. Please try again tomorrow.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'local_rate_limit' => 'The provider is busy. We will retry after a short wait.',
            'overloaded' => 'The provider is temporarily overloaded. We will retry shortly.',
            'idempotency_key_reused' => 'The provider could not match this request. Please contact support.',
            default => 'We could not confirm the provider’s result. Your request is saved and can be checked safely.',
        });
    }
}
