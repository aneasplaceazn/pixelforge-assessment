<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FinancialReports
{
    /** @return array<string, mixed> */
    public function build(User $operator, string $start, string $end): array
    {
        Gate::forUser($operator)->authorize('financial-reports');
        if (DB::transactionLevel() > 0) {
            return $this->read($start, $end);
        }

        return DB::transaction(function () use ($start, $end): array {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $this->read($start, $end);
        });
    }

    /** @return array<string, mixed> */
    private function read(string $start, string $end): array
    {
        $query = Generation::query()->whereRaw('COALESCE(completed_at, generations.created_at) >= ?', [CarbonImmutable::parse($start, 'UTC')])
            ->whereRaw('COALESCE(completed_at, generations.created_at) < ?', [CarbonImmutable::parse($end, 'UTC')->addDay()]);
        $totals = $this->totals(clone $query)->toBase()->first();
        $days = $this->totals(clone $query)->selectRaw("(COALESCE(completed_at, generations.created_at) AT TIME ZONE 'UTC')::date::text AS day")
            ->groupByRaw("(COALESCE(completed_at, generations.created_at) AT TIME ZONE 'UTC')::date")->orderBy('day')->toBase()->get();
        $users = $this->totals((clone $query)->leftJoin('users', 'users.id', '=', 'generations.user_id'))
            ->selectRaw("COALESCE('user:' || generations.user_id::text, 'deleted:' || COALESCE(generations.deleted_owner_id, generations.credit_account_id)::text, 'deleted:historical') AS identity, MAX(users.name) AS name")
            ->groupByRaw("COALESCE('user:' || generations.user_id::text, 'deleted:' || COALESCE(generations.deleted_owner_id, generations.credit_account_id)::text, 'deleted:historical')")
            ->orderBy('identity')->paginate(20, ['*'], 'users_page')->withQueryString();

        return ['totals' => $totals, 'days' => $days, 'users' => $users, 'pending' => Generation::query()->whereIn('status', ['queued', 'processing'])->where('created_at', '<', CarbonImmutable::parse($end, 'UTC')->addDay())->count()];
    }

    /** @param Builder<Generation> $query
     * @return Builder<Generation>
     */
    private function totals(Builder $query): Builder
    {
        return $query->selectRaw("COUNT(*) AS total, COUNT(*) FILTER (WHERE status = 'succeeded') AS succeeded, COUNT(*) FILTER (WHERE status = 'failed') AS failed,
            COALESCE(SUM(user_charge) FILTER (WHERE status = 'succeeded'),0)::text AS charges,
            COALESCE(SUM(provider_cost) FILTER (WHERE status = 'succeeded'),0)::text AS provider_cost,
            COALESCE(SUM(user_charge - provider_cost) FILTER (WHERE status = 'succeeded'),0)::text AS profit");
    }
}
