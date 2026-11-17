<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Generation> */
class GenerationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'request_key' => (string) Str::uuid(),
            'prompt' => 'A quiet lighthouse',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'status' => 'queued',
            'pricing_snapshot' => [],
            'estimated_cost' => '0.02',
            'estimated_charge' => '0.025',
            'next_sync_at' => now(),
        ];
    }
}
