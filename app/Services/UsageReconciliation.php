<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\Client;
use App\Services\Pixelforge\PricingCalculator;
use App\Services\Pixelforge\ProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class UsageReconciliation
{
    public function __construct(private Client $client, private PricingCalculator $pricing) {}

    /** @return array<string, mixed> */
    public function compare(User $operator, string $start, string $end): array
    {
        Gate::forUser($operator)->authorize('financial-reports');
        $scope = hash('sha256', (string) config('services.pixelforge.api_key'));
        $from = CarbonImmutable::parse($start, 'UTC');
        $until = CarbonImmutable::parse($end, 'UTC')->addDay();
        $base = Generation::query()->where('provider_scope', $scope);
        $pending = (clone $base)->whereIn('status', ['queued', 'processing'])->where('created_at', '<', $until)->count();
        $legacyPending = Generation::query()->whereNull('provider_scope')->whereIn('status', ['queued', 'processing'])->where('created_at', '<', $until)->count();
        $legacy = $legacyPending + Generation::query()->whereNull('provider_scope')->where('completed_at', '>=', $from)->where('completed_at', '<', $until)->count();
        $result = ['state' => 'unverified', 'checked_at' => now('UTC')->toIso8601String(), 'days' => [], 'pending_count' => $pending + $legacyPending, 'scoped_pending_count' => $pending, 'legacy_pending_count' => $legacyPending, 'legacy_scope_count' => $legacy, 'inventory_state' => 'unverified', 'missing_local' => [], 'missing_provider' => [], 'unsettled' => [], 'mismatched' => [], 'unscoped_provider_count' => 0, 'message' => null];
        try {
            $usage = $this->client->usage($start, $end);
            $rules = ['object' => 'required|in:usage', 'start_date' => 'required|in:'.$start, 'end_date' => 'required|in:'.$end,
                'data' => 'required|array', 'data.*.date' => 'required|date_format:Y-m-d', 'data.*.cost_usd' => ['required', 'string', 'regex:/\A\d+\.\d{9}\z/'], 'data.*.generations' => 'required|integer|min:0',
                'total.cost_usd' => ['required', 'string', 'regex:/\A\d+\.\d{9}\z/'], 'total.generations' => 'required|integer|min:0'];
            if (Validator::make($usage, $rules)->fails()) {
                throw new InvalidArgumentException('Invalid provider usage response.');
            }
            $days = [];
            for ($date = $from; $date < $until; $date = $date->addDay()) {
                $days[] = $date->toDateString();
            }
            $received = array_column($usage['data'], 'date');
            sort($received);
            if ($received !== $days) {
                throw new InvalidArgumentException('Provider usage did not contain exactly the requested UTC days.');
            }
            $totalProvider = '0';
            $totalLocal = '0';
            $totalCount = 0;
            $mismatch = false;
            foreach ($usage['data'] as $day) {
                $date = CarbonImmutable::parse($day['date'], 'UTC');
                $local = (clone $base)->where('status', 'succeeded')->where('completed_at', '>=', $date)->where('completed_at', '<', $date->addDay());
                $cost = (string) (clone $local)->sum('provider_cost');
                if (! is_numeric($cost)) {
                    throw new InvalidArgumentException('Invalid local provider cost.');
                }
                $count = $local->count();
                $matches = bccomp($cost, $day['cost_usd'], 18) === 0 && $count === $day['generations'];
                $result['days'][] = ['date' => $day['date'], 'local_cost' => bcadd($cost, '0', 18), 'provider_cost' => $day['cost_usd'], 'local_count' => $count, 'provider_count' => $day['generations'], 'state' => $matches ? 'reconciled' : 'mismatched'];
                $mismatch = $mismatch || ! $matches;
                $totalProvider = bcadd($totalProvider, $day['cost_usd'], 18);
                $totalLocal = bcadd($totalLocal, $cost, 18);
                $totalCount += $day['generations'];
            }
            if (bccomp($totalProvider, $usage['total']['cost_usd'], 18) !== 0 || $totalCount !== $usage['total']['generations']) {
                throw new InvalidArgumentException('Provider daily totals disagree with its overall total.');
            }
            $result['totals'] = ['local_cost' => $totalLocal, 'provider_cost' => $usage['total']['cost_usd']];
            $result['state'] = $legacy > 0 ? 'unverified' : ($mismatch ? 'mismatched' : ($pending > 0 ? 'pending' : 'reconciled'));
            $result['message'] = $legacy > 0 ? 'Legacy records have unknown scope, including '.$legacyPending.' unresolved requests created through the end date. Matching completed totals do not prove completeness. Verify provenance before using pixelforge:adopt-scope.' : null;
            $known = (clone $base)->whereNotNull('provider_id')->where('completed_at', '>=', $from)->where('completed_at', '<', $until)->pluck('provider_id')->flip();
            $seen = [];
            $cursor = null;
            $cursors = [];
            $complete = false;
            for ($page = 0; $page < 10; $page++) {
                $listing = $this->client->generations($cursor);
                $records = $listing['data'] ?? null;
                if (! is_array($records) || Validator::make($listing, ['data' => 'present|array|max:100', 'has_more' => 'required|boolean', 'next_cursor' => 'present|nullable|string', 'data.*.id' => 'required|string|starts_with:gen_', 'data.*.status' => 'required|in:queued,processing,succeeded,failed', 'data.*.metadata' => 'present|array'])->fails()) {
                    throw new InvalidArgumentException('Invalid provider generation listing.');
                }
                $localIds = collect($records)->pluck('metadata.generation_id')->filter(fn ($id): bool => is_string($id) && Str::isUuid($id));
                $providerIds = array_column($listing['data'], 'id');
                $locals = (clone $base)->where(fn ($query) => $query->whereIn('provider_id', $providerIds)->orWhereIn('id', $localIds))->get(['id', 'provider_id', 'status', 'provider_cost', 'completed_at']);
                foreach ($listing['data'] as $snapshot) {
                    $localId = $snapshot['metadata']['generation_id'] ?? null;
                    $localId = is_string($localId) && Str::isUuid($localId) ? $localId : null;
                    $local = $locals->first(fn (Generation $generation): bool => $generation->provider_id === $snapshot['id'] || $generation->id === $localId);
                    if (($snapshot['metadata']['scope'] ?? null) !== $scope && $local === null) {
                        if (! isset($snapshot['metadata']['scope'])) {
                            $result['unscoped_provider_count']++;
                        }

                        continue;
                    }
                    if (in_array($snapshot['status'], ['queued', 'processing'], true)) {
                        $result['unsettled'][] = $snapshot['id'];
                        if ($local === null) {
                            $result['missing_local'][] = $snapshot['id'];
                        }

                        continue;
                    }
                    if (! is_string($snapshot['completed_at'] ?? null) || ! strtotime($snapshot['completed_at'])) {
                        throw new InvalidArgumentException('Invalid completion timestamp in provider listing.');
                    }
                    $completed = CarbonImmutable::parse($snapshot['completed_at']);
                    if ($completed < $from || $completed >= $until) {
                        continue;
                    }
                    $seen[] = $snapshot['id'];
                    if ($local === null) {
                        $result['missing_local'][] = $snapshot['id'];
                    } elseif (! $local->isTerminal()) {
                        $result['unsettled'][] = $snapshot['id'];
                    } else {
                        if ($snapshot['status'] === 'succeeded' && (! is_array($snapshot['usage'] ?? null) || ! is_array($snapshot['rates'] ?? null))) {
                            throw new InvalidArgumentException('Missing usage or rates in provider listing.');
                        }
                        $cost = $snapshot['status'] === 'succeeded' ? $this->pricing->cost($snapshot['usage'], $snapshot['rates']) : '0';
                        if ($local->status !== $snapshot['status'] || $local->provider_id !== $snapshot['id'] || $local->completed_at?->utc()->toDateString() !== $completed->utc()->toDateString() || bccomp($local->provider_cost, $cost, 18) !== 0) {
                            $result['mismatched'][] = $snapshot['id'];
                        }
                    }
                }
                if (! $listing['has_more']) {
                    $complete = true;
                    break;
                }
                if (! is_string($listing['next_cursor']) || in_array($listing['next_cursor'], $cursors, true)) {
                    throw new InvalidArgumentException('Invalid pagination cursor.');
                }
                $cursor = $listing['next_cursor'];
                $cursors[] = $cursor;
            }
            if ($complete) {
                $result['missing_provider'] = $known->keys()->diff($seen)->values()->all();
            } else {
                $result['message'] = 'Inventory scan reached its 1,000-record safety limit. Usage totals were compared; the full inventory remains unverified.';
            }
            $result['inventory_state'] = ! $complete || $legacy > 0 || $result['unscoped_provider_count'] > 0 ? 'unverified' : (count($result['missing_local']) + count($result['missing_provider']) + count($result['mismatched']) > 0 ? 'mismatched' : (count($result['unsettled']) > 0 || $pending > 0 ? 'pending' : 'reconciled'));
        } catch (ProviderException|InvalidArgumentException $exception) {
            $result['state'] = 'unverified';
            $result['message'] = $exception instanceof ProviderException ? 'Provider comparison failed ('.$exception->providerCode.'). No financial records were changed. Try again later.' : $exception->getMessage();
            if ($exception instanceof ProviderException && $exception->retryNotBefore !== null) {
                $result['message'] = 'Provider comparison is paused until '.CarbonImmutable::createFromTimestampUTC($exception->retryNotBefore)->toIso8601String().'. No financial records were changed.';
            }
        }

        return $result;
    }
}
