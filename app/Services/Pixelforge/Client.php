<?php

namespace App\Services\Pixelforge;

use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Throwable;

class Client
{
    public function __construct(private ProviderCooldown $cooldown) {}

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
        if ($generation->provider_scope !== null && ! hash_equals($generation->provider_scope, hash('sha256', (string) config('services.pixelforge.api_key')))) {
            throw new ProviderException('key_scope_changed', uncertain: true);
        }

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

    /** @return array<string, mixed> */
    public function generations(?string $cursor = null): array
    {
        return $this->request('GET', 'generations', array_filter(['limit' => 100, 'cursor' => $cursor], fn ($value): bool => $value !== null));
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
            $this->checkCooldown($method, $path);
            try {
                $allowed = Cache::lock('pixelforge:budget-lock', 5)->block(2, function (): bool {
                    if (RateLimiter::tooManyAttempts('pixelforge:requests', 45)) {
                        return false;
                    }
                    RateLimiter::hit('pixelforge:requests', 60);

                    return true;
                });
                $localRetryAfter = $allowed ? 0 : max(1, RateLimiter::availableIn('pixelforge:requests'));
            } catch (Throwable) {
                throw new ProviderException('local_limiter_unavailable', true, 5, deferred: true);
            }
            if (! $allowed) {
                throw new ProviderException('local_rate_limit', true, $localRetryAfter, deferred: true);
            }
        }
        $request = $this->http();
        if ($authenticated) {
            $request = $request->withToken($token);
        }
        if ($key !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $key]);
        }
        $observation = null;
        if ($authenticated) {
            try {
                $observation = $this->cooldown->begin($method, $path);
            } catch (ProviderException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new ProviderException('cooldown_unavailable', true, 5, deferred: true);
            }
        }
        try {
            $response = $method === 'POST' ? $request->post($path, $data) : $request->get($path, $data);
        } catch (ConnectionException) {
            $this->finishObservation($observation, $method);
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
            $retryAfter = max(0, (int) $response->header('Retry-After'));
            $seconds = null;
            $scope = 'account';
            if ($authenticated && $response->status() === 429 && $code === 'rate_limit_exceeded') {
                $seconds = max(1, $retryAfter);
            } elseif ($authenticated && $method === 'GET' && $retryable) {
                $seconds = max(5, $retryAfter);
                $scope = 'GET:'.$path;
            }
            $deadline = $this->finishObservation($observation, $method, $seconds, $scope);
            throw new ProviderException($code, $retryable, $retryAfter, $method === 'POST' && $code === 'provider_error', retryNotBefore: $deadline);
        }
        $this->finishObservation($observation, $method);
        $body = $response->json();
        if (! is_array($body) || array_is_list($body)) {
            throw new ProviderException('invalid_response', true, 5, $method === 'POST');
        }

        return $body;
    }

    private function checkCooldown(string $method, string $path): void
    {
        try {
            $deadline = $this->cooldown->deadline($method, $path);
        } catch (Throwable) {
            throw new ProviderException('cooldown_unavailable', true, 5, deferred: true);
        }
        $now = CarbonImmutable::now();
        if ($deadline > $now->getTimestamp()) {
            throw new ProviderException('provider_cooldown', true, $deadline - $now->getTimestamp(), deferred: true, retryNotBefore: $deadline);
        }
    }

    private function finishObservation(?string $token, string $method, ?int $seconds = null, string $scope = 'account'): ?int
    {
        if ($token === null) {
            return null;
        }
        try {
            return $this->cooldown->finish($token, $seconds, $scope);
        } catch (Throwable) {
            throw new ProviderException('cooldown_unavailable', true, max(5, $seconds ?? 0), uncertain: $method === 'POST');
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.pixelforge.base_url'))
            ->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(3)->timeout(10);
    }
}
