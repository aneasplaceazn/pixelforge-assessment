<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerationRequest;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        $generations = Generation::query()->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(12)
            ->through(fn (Generation $generation): array => [
                'id' => $generation->id, 'prompt' => $generation->prompt,
                'size' => $generation->size, 'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'status' => $generation->status,
                'estimated_charge' => $generation->estimated_charge,
                'user_charge' => $generation->user_charge,
                'image_url' => $generation->image_url,
                'image_url_expires_at' => $generation->image_url_expires_at?->toIso8601String(),
                'error_message' => ! $generation->isTerminal() && $generation->next_sync_at === null ? Generation::PAUSED_MESSAGE : $generation->error_message,
                'can_retry' => ! $generation->isTerminal() && $generation->next_sync_at === null,
                'created_at' => $generation->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Dashboard', ['generations' => $generations]);
    }

    public function store(GenerationRequest $request): RedirectResponse
    {
        $key = $request->string('quote')->toString();
        $existing = Generation::query()->where('request_key', $key)->first();
        if ($existing !== null) {
            abort_unless($existing->user_id === $request->user()->id, 404);
            if ($request->parameters() !== array_intersect_key($existing->providerParameters(), $request->parameters())) {
                throw ValidationException::withMessages(['quote' => 'Your settings changed. Please get a new estimate.']);
            }

            return to_route('dashboard');
        }
        $quote = Cache::get('generation-quote:'.$key);
        if (! is_array($quote) || $quote['expires_at'] <= now()->getTimestamp()) {
            throw ValidationException::withMessages(['quote' => 'This estimate expired or is unavailable. Please get a new estimate.']);
        }
        if ($quote['user_id'] !== $request->user()->id || ! hash_equals($quote['parameters_hash'], hash('sha256', json_encode($request->parameters(), JSON_THROW_ON_ERROR)))) {
            throw ValidationException::withMessages(['quote' => 'Your settings changed. Please get a new estimate.']);
        }
        $generation = DB::transaction(function () use ($request, $quote): Generation {
            abort_unless(User::query()->lockForUpdate()->find($request->user()->id) !== null, 403);

            return Generation::query()->firstOrCreate(['request_key' => $quote['request_key']], [
                ...$request->parameters(),
                'user_id' => $request->user()->id,
                'pricing_snapshot' => $quote['pricing'],
                'estimated_cost' => $quote['estimate']['provider_cost'],
                'estimated_charge' => $quote['estimate']['user_charge'],
                'next_sync_at' => now(),
            ]);
        }, 3);
        SyncGeneration::dispatch($generation->id);

        return to_route('dashboard');
    }

    public function retry(Request $request, Generation $generation): RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
        DB::transaction(function () use ($generation): void {
            $locked = Generation::query()->lockForUpdate()->findOrFail($generation->id);
            if (! $locked->isTerminal() && $locked->next_sync_at === null) {
                $locked->update(['sync_attempts' => 0, 'next_sync_at' => $locked->nextCheckAt(), 'error_code' => null, 'error_message' => null]);
            }
        });
        SyncGeneration::dispatch($generation->id);

        return to_route('dashboard');
    }
}
