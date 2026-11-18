<?php

namespace App\Services;

use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Models\CreditEntryKind;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class Credits
{
    /** @return array{available: numeric-string, reserved: numeric-string, outstanding: numeric-string} */
    public function balances(?string $accountId): array
    {
        $totals = CreditEntry::query()->where('credit_account_id', $accountId)->selectRaw('COALESCE(SUM(available_delta),0)::text AS available, COALESCE(SUM(reserved_delta),0)::text AS reserved, COALESCE(SUM(outstanding_delta),0)::text AS outstanding')->toBase()->first();

        return ['available' => $this->decimal($totals->available), 'reserved' => $this->decimal($totals->reserved), 'outstanding' => $this->decimal($totals->outstanding)];
    }

    /** @return array{available: numeric-string, reserved: numeric-string, outstanding: numeric-string} */
    public function forUser(User $user): array
    {
        return $this->balances(CreditAccount::query()->where('user_id', $user->id)->value('id'));
    }

    public function canAfford(User $user, string $amount): bool
    {
        $amount = $this->decimal($amount);
        $balance = $this->forUser($user);

        return bccomp($balance['outstanding'], '0', 18) === 0 && bccomp($balance['available'], $amount, 18) >= 0;
    }

    public function purchase(User $user, string $amount, string $key): void
    {
        if (! in_array($amount, ['5', '10', '20'], true)) {
            throw ValidationException::withMessages(['amount' => 'Choose $5, $10 or $20 in simulated credits.']);
        }
        DB::transaction(function () use ($user, $amount, $key): void {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $account = $this->account($user);
            $existing = CreditEntry::query()->where('credit_account_id', $account->id)->where('kind', CreditEntryKind::Purchase)->where('request_key', $key)->first();
            if ($existing !== null) {
                if (bccomp($existing->amount, $amount, 18) !== 0) {
                    throw ValidationException::withMessages(['purchase_key' => 'This purchase key already belongs to a different amount.']);
                }

                return;
            }
            $this->entry($account->id, CreditEntryKind::Purchase, $amount, $amount, '0', '0', key: $key);
            $this->payOutstanding($account->id, key: $key);
        }, 3);
    }

    /** Called inside the submission transaction with its user row locked. */
    public function reserve(User $user, Generation $generation): void
    {
        $this->requireTransaction();
        if ($generation->user_id !== $user->id) {
            throw new LogicException('The reservation must belong to the generation owner.');
        }
        $account = $this->account($user);
        if (CreditEntry::query()->where('generation_id', $generation->id)->where('kind', CreditEntryKind::Reserve)->exists()) {
            return;
        }
        $balance = $this->balances($account->id);
        if (bccomp($balance['outstanding'], '0', 18) > 0 || bccomp($balance['available'], $generation->estimated_charge, 18) < 0) {
            throw ValidationException::withMessages(['quote' => 'Insufficient available credits. Buy simulated credits and try again.']);
        }
        $generation->update(['credit_account_id' => $account->id]);
        $this->entry($account->id, CreditEntryKind::Reserve, $generation->estimated_charge, bcsub('0', $generation->estimated_charge, 18), $generation->estimated_charge, '0', $generation->id);
    }

    /** Called after terminal state is saved, in the same transaction and generation lock. */
    public function settle(Generation $generation): void
    {
        $this->requireTransaction();
        if ($generation->credit_account_id === null || ! $generation->isTerminal()) {
            return;
        }
        CreditAccount::query()->lockForUpdate()->findOrFail($generation->credit_account_id);
        if (CreditEntry::query()->where('generation_id', $generation->id)->where('kind', CreditEntryKind::Release)->exists()) {
            return;
        }
        $reservation = CreditEntry::query()->where('generation_id', $generation->id)->where('kind', CreditEntryKind::Reserve)->firstOrFail();
        $remaining = $reservation->amount;
        if ($generation->status === 'succeeded') {
            $charge = $generation->user_charge;
            if ($charge === null) {
                throw new LogicException('Successful generation requires a final charge.');
            }
            $covered = bccomp($charge, $remaining, 18) < 0 ? $charge : $remaining;
            $extra = bcsub($charge, $covered, 18);
            $available = $this->balances($generation->credit_account_id)['available'];
            $extraPaid = bccomp($available, $extra, 18) < 0 ? $available : $extra;
            $debt = bcsub($extra, $extraPaid, 18);
            $this->entry($generation->credit_account_id, CreditEntryKind::Settle, $charge, bcsub('0', $extraPaid, 18), bcsub('0', $covered, 18), $debt, $generation->id);
            $remaining = bcsub($remaining, $covered, 18);
        }
        $this->entry($generation->credit_account_id, CreditEntryKind::Release, $remaining, $remaining, bcsub('0', $remaining, 18), '0', $generation->id);
        $this->payOutstanding($generation->credit_account_id, generation: $generation->id);
    }

    private function payOutstanding(string $accountId, ?string $generation = null, ?string $key = null): void
    {
        $balance = $this->balances($accountId);
        $paid = bccomp($balance['available'], $balance['outstanding'], 18) < 0 ? $balance['available'] : $balance['outstanding'];
        if (bccomp($paid, '0', 18) > 0) {
            $this->entry($accountId, CreditEntryKind::DebtPayment, $paid, bcsub('0', $paid, 18), '0', bcsub('0', $paid, 18), $generation, $key);
        }
    }

    private function account(User $user): CreditAccount
    {
        $account = CreditAccount::query()->firstOrCreate(['user_id' => $user->id]);

        return CreditAccount::query()->lockForUpdate()->findOrFail($account->id);
    }

    private function entry(string $account, CreditEntryKind $kind, string $amount, string $available, string $reserved, string $outstanding, ?string $generation = null, ?string $key = null): void
    {
        CreditEntry::create(['credit_account_id' => $account, 'kind' => $kind, 'amount' => $amount, 'available_delta' => $available, 'reserved_delta' => $reserved, 'outstanding_delta' => $outstanding, 'generation_id' => $generation, 'request_key' => $key]);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Credit operations require the owning transaction.');
        }
    }

    /** @return numeric-string */
    private function decimal(string $value): string
    {
        if (! is_numeric($value) || ! preg_match('/\A\d+(?:\.\d{1,18})?\z/', $value)) {
            throw new LogicException('Invalid credit balance or amount.');
        }

        return bcadd($value, '0', 18);
    }
}
