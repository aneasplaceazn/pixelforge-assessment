<?php

namespace App\Console\Commands;

use App\Jobs\SyncGeneration;
use App\Models\Generation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResumeGeneration extends Command
{
    protected $signature = 'pixelforge:resume {generation_id : Saved generation to reconcile}';

    protected $description = 'Resume a paused saved request, including deleted accounts, preserving its key and cooldown';

    public function handle(): int
    {
        $id = $this->argument('generation_id');
        $resumed = DB::transaction(function () use ($id): bool {
            $generation = Generation::query()->lockForUpdate()->find($id);
            if ($generation === null || $generation->isTerminal() || $generation->next_sync_at !== null) {
                return false;
            }
            $generation->update(['sync_attempts' => 0, 'next_sync_at' => $generation->nextCheckAt(), 'error_code' => null, 'error_message' => null]);

            return true;
        }, 3);
        if (! $resumed) {
            $this->error('No paused request found.');

            return self::FAILURE;
        }
        SyncGeneration::dispatch($id);
        $this->info('Saved request scheduled; its provider cooldown still applies.');

        return self::SUCCESS;
    }
}
