<?php

namespace App\Http\Controllers;

use App\Services\FinancialReports;
use App\Services\UsageReconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FinancialReportController extends Controller
{
    public function index(Request $request, FinancialReports $reports): Response
    {
        Gate::authorize('financial-reports');
        [$start, $end] = $this->dates($request);

        return Inertia::render('FinancialReports', ['start' => $start, 'end' => $end, 'report' => $reports->build($request->user(), $start, $end), 'reconciliation' => Cache::get($this->cacheKey($request, $start, $end))]);
    }

    public function reconcile(Request $request, UsageReconciliation $reconciliation): RedirectResponse
    {
        Gate::authorize('financial-reports');
        [$start, $end] = $this->dates($request);
        Cache::put($this->cacheKey($request, $start, $end), $reconciliation->compare($request->user(), $start, $end), now()->addMinutes(5));

        return to_route('reports.index', ['start' => $start, 'end' => $end]);
    }

    /** @return array{string, string} */
    private function dates(Request $request): array
    {
        $data = $request->validate(['start' => ['sometimes', 'required', 'date_format:Y-m-d'], 'end' => ['sometimes', 'required', 'date_format:Y-m-d']]);
        $start = $data['start'] ?? now('UTC')->subDays(29)->toDateString();
        $end = $data['end'] ?? now('UTC')->toDateString();
        $days = CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end));
        if ($days < 0 || $days > 91) {
            throw ValidationException::withMessages(['end' => 'Choose an inclusive UTC range of 1 to 92 days.']);
        }

        return [$start, $end];
    }

    private function cacheKey(Request $request, string $start, string $end): string
    {
        return 'financial-reconciliation:v2:'.$request->user()->id.':'.$start.':'.$end.':'.hash('sha256', (string) config('services.pixelforge.api_key'));
    }
}
