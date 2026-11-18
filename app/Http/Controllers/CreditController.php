<?php

namespace App\Http\Controllers;

use App\Models\CreditAccount;
use App\Models\CreditEntry;
use App\Services\Credits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    public function index(Request $request, Credits $credits): Response
    {
        $accountId = CreditAccount::query()->where('user_id', $request->user()->id)->value('id');

        return Inertia::render('Credits', [
            'credits' => $credits->balances($accountId),
            'entries' => CreditEntry::query()->where('credit_account_id', $accountId)->orderByDesc('id')->paginate(15)->through(fn (CreditEntry $entry): array => $entry->only(['id', 'kind', 'amount', 'available_delta', 'reserved_delta', 'outstanding_delta', 'created_at'])),
        ]);
    }

    public function store(Request $request, Credits $credits): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'string', Rule::in(['5', '10', '20'])], 'purchase_key' => ['required', 'uuid']]);
        $credits->purchase($request->user(), $data['amount'], $data['purchase_key']);

        return to_route('credits.index');
    }
}
