<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $estimated_cost
 * @property numeric-string $estimated_charge
 * @property numeric-string|null $provider_cost
 * @property numeric-string|null $user_charge
 * @property CarbonImmutable|null $next_sync_at
 * @property CarbonImmutable|null $retry_not_before
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $image_url_expires_at
 * @property list<string> $reference_images
 * @property array<string, mixed> $pricing_snapshot
 * @property array<string, int>|null $usage
 * @property array<string, string>|null $rates
 * @property array<string, string>|null $provider_metadata
 */
class Generation extends Model
{
    public const PAUSED_MESSAGE = 'Automatic checks paused. Resume this saved request with the same request key; you will not be charged twice.';

    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'submission_uncertain' => 'boolean',
            'pricing_snapshot' => 'array',
            'provider_metadata' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'estimated_cost' => 'decimal:18',
            'estimated_charge' => 'decimal:18',
            'provider_cost' => 'decimal:18',
            'user_charge' => 'decimal:18',
            'next_sync_at' => 'immutable_datetime',
            'retry_not_before' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'image_url_expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }

    public function eraseContent(): void
    {
        $this->fill(['prompt' => '', 'reference_images' => [], 'image_url' => null, 'image_url_expires_at' => null, 'error_message' => null, 'pricing_snapshot' => []]);
    }

    public function nextCheckAt(int $delay = 0): CarbonImmutable
    {
        $at = CarbonImmutable::now()->addSeconds($delay);
        if ($delay > 0) {
            // Eloquent persists whole seconds; truncating fractions would permit an early retry.
            $at = $at->ceilSecond();
        }

        return $this->retry_not_before?->greaterThan($at) ? $this->retry_not_before : $at;
    }

    /** @return array{prompt: string, size: string, quality: string, reference_images: list<string>, metadata: array<string, string>} */
    public function providerParameters(): array
    {
        return [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'metadata' => $this->provider_metadata ?? ['generation_id' => $this->id],
        ];
    }
}
