<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerationRequest;
use App\Services\Credits;
use App\Services\Pixelforge\Client;
use App\Services\Pixelforge\PricingCalculator;
use App\Services\Pixelforge\ProviderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GenerationEstimateController extends Controller
{
    public function __invoke(GenerationRequest $request, Client $client, PricingCalculator $calculator): JsonResponse
    {
        try {
            $pricing = $client->pricing();
        } catch (ProviderException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
        $parameters = $request->parameters();
        $estimate = $calculator->estimate($parameters['prompt'], $parameters['size'], $parameters['quality'], count($parameters['reference_images']), $pricing);
        $quote = [
            'user_id' => $request->user()->id,
            'request_key' => (string) Str::uuid(),
            'parameters_hash' => hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR)),
            'pricing' => $pricing,
            'estimate' => $estimate,
            'expires_at' => now()->addMinutes(5)->getTimestamp(),
        ];

        Cache::put('generation-quote:'.$quote['request_key'], $quote, now()->addMinutes(5));

        return response()->json([...$estimate, 'quote' => $quote['request_key'], 'expires_at' => $quote['expires_at'], 'can_afford' => app(Credits::class)->canAfford($request->user(), $estimate['user_charge'])]);
    }
}
