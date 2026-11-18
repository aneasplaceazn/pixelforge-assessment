<?php

namespace App\Console\Commands;

use App\Models\Generation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pixelforge:adopt-scope {--confirm-dedicated-key : Confirm every legacy record belongs to the currently configured API key}')]
#[Description('Preview or explicitly assign API-key scope to legacy generation records without changing submission parameters')]
class AdoptProviderScope extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $key = config('services.pixelforge.api_key');
        if (! is_string($key) || $key === '') {
            $this->error('Configure the dedicated provider API key first.');

            return self::FAILURE;
        }
        $query = Generation::query()->whereNull('provider_scope');
        $this->info($query->count().' legacy records have no confirmed API-key scope.');
        if (! $this->option('confirm-dedicated-key')) {
            $this->warn('Preview only. Independently verify all legacy records belong to this API key before using --confirm-dedicated-key.');

            return self::SUCCESS;
        }
        $query->update(['provider_scope' => hash('sha256', $key)]);
        $this->info('Legacy scope assigned. Generation parameters and financial records are unchanged.');

        return self::SUCCESS;
    }
}
