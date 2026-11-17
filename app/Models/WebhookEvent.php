<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $processed_at
 */
class WebhookEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
