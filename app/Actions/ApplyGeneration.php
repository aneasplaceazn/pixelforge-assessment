<?php

namespace App\Actions;

use App\Models\Generation;
use App\Services\Credits;
use App\Services\Pixelforge\PricingCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class ApplyGeneration
{
    public function __construct(private PricingCalculator $pricing, private Credits $credits) {}

    /** @param array<string, mixed> $snapshot */
    public function handle(array $snapshot, ?string $expectedId = null): bool
    {
        $rules = [
            'id' => ['required', 'string', 'max:255', 'starts_with:gen_'],
            'object' => ['required', 'in:generation'],
            'status' => ['required', 'in:queued,processing,succeeded,failed'],
            'metadata.generation_id' => ['required', 'uuid'],
            'prompt' => ['required', 'string'], 'size' => ['required', 'string'], 'quality' => ['required', 'string'],
            'reference_images' => ['present', 'array'],
            'completed_at' => ['required_if:status,succeeded,failed', 'nullable', 'date'],
            'image_url' => ['required_if:status,succeeded', 'nullable', 'url:http,https'],
            'image_url_expires_at' => ['required_if:status,succeeded', 'nullable', 'date'],
            'error.code' => ['required_if:status,failed', 'string', 'max:255'],
            'error.message' => ['required_if:status,failed', 'string', 'max:2000'],
        ];
        foreach (PricingCalculator::TOKEN_TYPES as $type) {
            $rules['usage.'.$type] = ['required_if:status,succeeded', 'integer', 'min:0', 'max:1000000000'];
            $rules['rates.'.$type] = ['required', 'string', 'regex:/\A\d{1,6}(?:\.\d{1,9})?\z/'];
        }
        if (Validator::make($snapshot, $rules)->fails()) {
            throw new InvalidArgumentException('Invalid generation snapshot.');
        }

        return DB::transaction(function () use ($snapshot, $expectedId): bool {
            $localId = $snapshot['metadata']['generation_id'];
            if (! is_string($localId)) {
                throw new InvalidArgumentException('Invalid local generation ID.');
            }
            if ($expectedId !== null && $localId !== $expectedId) {
                throw new InvalidArgumentException('Generation identity mismatch.');
            }
            $generation = Generation::query()->lockForUpdate()->find($localId);
            if ($generation === null) {
                return false;
            }
            if ($generation->user_id === null && $generation->isTerminal()) {
                if ($generation->provider_id !== null && $generation->provider_id !== $snapshot['id']) {
                    throw new InvalidArgumentException('Generation identity mismatch.');
                }

                return true;
            }
            if (($generation->provider_id !== null && $generation->provider_id !== $snapshot['id'])
                || $generation->prompt !== $snapshot['prompt'] || $generation->size !== $snapshot['size']
                || $generation->quality !== $snapshot['quality'] || $generation->reference_images !== $snapshot['reference_images']) {
                throw new InvalidArgumentException('Generation parameters mismatch.');
            }
            if ($generation->isTerminal()) {
                return true;
            }
            $generation->provider_id = $snapshot['id'];
            $generation->rates = $snapshot['rates'];
            if ($generation->status === 'processing' && $snapshot['status'] === 'queued') {
                $generation->save();

                return true;
            }
            $generation->status = $snapshot['status'];
            $generation->error_code = null;
            $generation->error_message = null;
            $generation->sync_attempts = 0;
            $generation->next_sync_at = $generation->nextCheckAt(30);
            if ($snapshot['status'] === 'succeeded') {
                $generation->usage = $snapshot['usage'];
                $generation->provider_cost = $this->pricing->cost($snapshot['usage'], $snapshot['rates']);
                $generation->user_charge = $this->pricing->charge($generation->provider_cost);
                $generation->image_url = $snapshot['image_url'];
                $generation->image_url_expires_at = CarbonImmutable::parse($snapshot['image_url_expires_at']);
            }
            if ($snapshot['status'] === 'failed') {
                $generation->provider_cost = '0';
                $generation->user_charge = '0';
                $generation->error_code = $snapshot['error']['code'];
                $generation->error_message = 'The provider could not generate this image. You were not charged. Try a new generation.';
            }
            if ($generation->isTerminal()) {
                $generation->completed_at = CarbonImmutable::parse($snapshot['completed_at']);
                $generation->next_sync_at = null;
                $generation->retry_not_before = null;
                if ($generation->user_id === null) {
                    $generation->eraseContent();
                }
            }
            $generation->save();
            $this->credits->settle($generation);

            return true;
        }, 3);
    }
}
