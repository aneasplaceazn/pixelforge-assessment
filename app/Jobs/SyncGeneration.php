<?php

namespace App\Jobs;

use App\Actions\ApplyGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\Client;
use App\Services\Pixelforge\ProviderException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 90;

    public int $backoff = 30;

    public function __construct(public string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    public function handle(Client $client, ApplyGeneration $apply): void
    {
        Cache::lock('pixelforge:sync:'.$this->generationId, 45)->get(function () use ($client, $apply): void {
            $wasUncertain = false;
            $generation = DB::transaction(function () use (&$wasUncertain): ?Generation {
                $generation = Generation::query()->lockForUpdate()->find($this->generationId);
                if ($generation === null || $generation->isTerminal() || $generation->next_sync_at === null || $generation->next_sync_at->isFuture()) {
                    return null;
                }
                if ($generation->sync_attempts >= 5) {
                    $generation->update(['next_sync_at' => null, 'error_code' => 'retries_exhausted', 'error_message' => Generation::PAUSED_MESSAGE]);

                    return null;
                }
                if ($generation->retry_not_before?->isFuture()) {
                    $generation->update(['next_sync_at' => $generation->retry_not_before]);

                    return null;
                }
                $generation->increment('sync_attempts');
                $wasUncertain = $generation->submission_uncertain;
                $generation->update(['next_sync_at' => now()->addSeconds(60), 'submission_uncertain' => $generation->provider_id === null || $wasUncertain]);

                return $generation;
            });
            if ($generation === null) {
                return;
            }
            try {
                $snapshot = $generation->provider_id === null ? $client->create($generation) : $client->retrieve($generation->provider_id);
                $apply->handle($snapshot, $generation->id);
            } catch (InvalidArgumentException) {
                $this->recordFailure(new ProviderException('invalid_response', true, 30, $generation->provider_id === null), $wasUncertain);
            } catch (ProviderException $exception) {
                $this->recordFailure($exception, $wasUncertain);
            }
        });
    }

    private function recordFailure(ProviderException $exception, bool $wasUncertain): void
    {
        DB::transaction(function () use ($exception, $wasUncertain): void {
            $generation = Generation::query()->lockForUpdate()->findOrFail($this->generationId);
            if ($generation->isTerminal()) {
                return;
            }
            $generation->submission_uncertain = $wasUncertain || $exception->uncertain;
            $generation->error_code = $exception->providerCode;
            $generation->error_message = $exception->getMessage();
            $delay = max($exception->retryAfter, 5 * (2 ** min($generation->sync_attempts, 8)));
            $generation->retry_not_before = $generation->nextCheckAt($delay);
            $generation->next_sync_at = $exception->retryable && $generation->sync_attempts < 5 ? $generation->retry_not_before : null;
            if ($generation->next_sync_at === null && $exception->retryable) {
                $generation->error_message = Generation::PAUSED_MESSAGE;
            }
            if ((! $exception->retryable || $generation->user_id === null) && ! $generation->submission_uncertain && $generation->provider_id === null) {
                $generation->status = 'failed';
                $generation->provider_cost = '0';
                $generation->user_charge = '0';
                $generation->completed_at = now();
                $generation->next_sync_at = null;
                $generation->retry_not_before = null;
                if ($generation->user_id === null) {
                    $generation->eraseContent();
                }
            }
            $generation->save();
        });
    }
}
