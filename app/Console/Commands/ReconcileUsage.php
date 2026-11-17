<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\Pixelforge\Client;
use App\Services\Pixelforge\ProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class ReconcileUsage extends Command
{
    protected $signature = 'pixelforge:usage {start_date} {end_date}';

    protected $description = 'Compare exact provider costs by UTC completion day; report discrepancies without rewriting records';

    public function handle(Client $client): int
    {
        $start = $this->argument('start_date');
        $end = $this->argument('end_date');
        $validator = Validator::make(['start' => $start, 'end' => $end], ['start' => 'required|date_format:Y-m-d', 'end' => 'required|date_format:Y-m-d|after_or_equal:start']);
        if ($validator->fails() || CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) > 91) {
            $this->error('Use an inclusive UTC range of at most 92 days (YYYY-MM-DD).');

            return self::FAILURE;
        }
        try {
            $usage = $client->usage($start, $end);
        } catch (ProviderException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if (Validator::make($usage, ['data' => 'required|array', 'data.*.date' => 'required|date_format:Y-m-d', 'data.*.cost_usd' => ['required', 'string', 'regex:/\A\d+\.\d{9}\z/']])->fails()) {
            $this->error('Invalid provider usage response.');

            return self::FAILURE;
        }
        $expectedDays = [];
        for ($date = CarbonImmutable::parse($start); $date->toDateString() <= $end; $date = $date->addDay()) {
            $expectedDays[] = $date->toDateString();
        }
        $receivedDays = array_column($usage['data'], 'date');
        sort($receivedDays);
        if ($receivedDays !== $expectedDays) {
            $this->error('Provider usage did not contain exactly the requested days.');

            return self::FAILURE;
        }
        $matched = true;
        $rows = [];
        foreach ($usage['data'] as $day) {
            $date = CarbonImmutable::parse($day['date'], 'UTC');
            $local = (string) Generation::query()->where('status', 'succeeded')
                ->where('completed_at', '>=', $date)->where('completed_at', '<', $date->addDay())->sum('provider_cost');
            if (! is_numeric($local)) {
                $this->error('Invalid local cost total.');

                return self::FAILURE;
            }
            $equal = bccomp($local, $day['cost_usd'], 18) === 0;
            $matched = $matched && $equal;
            $rows[] = [$day['date'], $local, $day['cost_usd'], $equal ? 'MATCH' : 'MISMATCH'];
        }
        $this->table(['UTC day', 'Local provider cost (USD)', 'Provider cost (USD)', 'Result'], $rows);

        return $matched ? self::SUCCESS : self::FAILURE;
    }
}
