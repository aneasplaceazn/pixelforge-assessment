<?php

namespace App\Actions;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Credits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeleteAccount
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $ids = Generation::query()->where('user_id', $user->id)->pluck('id');
            // Match the webhook processor's event-before-generation lock order.
            $events = WebhookEvent::query()->whereIn('payload->data->object->metadata->generation_id', $ids)->orderBy('id')->lockForUpdate()->get();
            $generations = Generation::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $deletedOwner = (string) Str::uuid();
            foreach ($generations as $generation) {
                $generation->deleted_owner_id = $deletedOwner;
                if (! $generation->isTerminal() && $generation->provider_id === null && ! $generation->submission_uncertain) {
                    $generation->fill(['status' => 'failed', 'provider_cost' => '0', 'user_charge' => '0', 'completed_at' => now(), 'next_sync_at' => null, 'retry_not_before' => null, 'error_code' => 'account_deleted']);
                }
                if ($generation->isTerminal()) {
                    $generation->eraseContent();
                }
                $generation->save();
                app(Credits::class)->settle($generation);
            }
            $terminalIds = $generations->filter(fn (Generation $generation): bool => $generation->isTerminal())->pluck('id');
            foreach ($events as $event) {
                if ($terminalIds->contains(data_get($event->payload, 'data.object.metadata.generation_id'))) {
                    $event->update(['payload' => [], 'processed_at' => now(), 'next_attempt_at' => null]);
                }
            }
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        }, 3);
    }
}
