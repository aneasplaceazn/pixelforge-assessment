<?php

namespace App\Services\Pixelforge;

use App\Models\Generation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class Client
{
    /** @return array<string, mixed> */
    public function pricing(): array
    {
        return Cache::remember('pixelforge:pricing', 60, function (): array {
            $pricing = $this->request('GET', 'pricing', authenticated: false);
            $rules = [
                'currency' => 'required|in:usd', 'unit' => 'required|in:per_1m_tokens',
                'tokens.characters_per_text_token' => 'required|integer|min:1|max:1000',
                'tokens.text_token_variance' => 'required|numeric|between:0,1',
                'tokens.per_reference_image' => 'required|integer|min:0|max:1000000',
            ];
            foreach (PricingCalculator::TOKEN_TYPES as $type) {
                $rules['rates.'.$type] = ['required', 'string', 'regex:/\A\d{1,6}(?:\.\d{1,9})?\z/'];
            }
            foreach (['low', 'medium', 'high'] as $quality) {
                foreach (['1024x1024', '1536x1024', '1024x1536'] as $size) {
                    $rules["tokens.output.$quality.$size"] = 'required|integer|min:0|max:1000000000';
                }
                $rules["tokens.reasoning.$quality.max"] = 'required|integer|min:0|max:1000000000';
            }
            if (Validator::make($pricing, $rules)->fails()) {
                throw new ProviderException('invalid_response', true, 30);
            }

            return $pricing;
        });
    }

    /** @return array<string, mixed> */
    public function create(Generation $generation): array
    {
        return $this->request('POST', 'generations', $generation->providerParameters(), $generation->request_key);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->request('GET', 'generations/'.rawurlencode($id));
    }

    /** @return array<string, mixed> */
    public function usage(string $start, string $end): array
    {
        return $this->request('GET', 'usage', ['start_date' => $start, 'end_date' => $end]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $data = [], ?string $key = null, bool $authenticated = true): array
    {
        $token = config('services.pixelforge.api_key');
        if ($authenticated && ! $token) {
            throw new ProviderException('not_configured');
        }
        if ($authenticated) {
            $allowed = Cache::lock('pixelforge:budget-lock', 5)->block(2, function (): bool {
                if (RateLimiter::tooManyAttempts('pixelforge:requests', 45)) {
                    return false;
                }
                RateLimiter::hit('pixelforge:requests', 60);

                return true;
            });
            if (! $allowed) {
                throw new ProviderException('local_rate_limit', true, max(1, RateLimiter::availableIn('pixelforge:requests')));
            }
        }
        $request = $this->http();
        if ($authenticated) {
            $request = $request->withToken($token);
        }
        if ($key !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $key]);
        }
        try {
            $response = $method === 'POST' ? $request->post($path, $data) : $request->get($path, $data);
        } catch (ConnectionException) {
            throw new ProviderException('connection_failed', true, 5, $method === 'POST');
        }
        if (! $response->successful()) {
            $code = $response->json('error.code');
            $known = ['invalid_api_key', 'content_policy_violation', 'validation_failed', 'daily_limit_exceeded', 'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', 'idempotency_key_reused', 'not_found'];
            $code = is_string($code) && in_array($code, $known, true) ? $code : 'provider_error';
            $retryable = in_array($code, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded'], true) || $response->serverError() || $response->status() === 429;
            if ($code === 'daily_limit_exceeded') {
                $retryable = false;
            }
            throw new ProviderException($code, $retryable, max(0, (int) $response->header('Retry-After')), $method === 'POST' && $code === 'provider_error');
        }
        $body = $response->json();
        if (! is_array($body) || array_is_list($body)) {
            throw new ProviderException('invalid_response', true, 5, $method === 'POST');
        }

        return $body;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.base_url'))
            ->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(3)->timeout(10);
    }
}
