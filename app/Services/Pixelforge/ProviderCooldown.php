<?php

namespace App\Services\Pixelforge;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;

class ProviderCooldown
{
    /** A separate read-endpoint pause must never stop unrelated generations. */
    public function deadline(string $method, string $path): int
    {
        $account = $this->read('account');

        return $method === 'GET' ? max($account, $this->read('GET:'.$path)) : $account;
    }

    /** Commit a durable observation marker before HTTP, independently of financial transactions. */
    public function begin(string $method, string $path): string
    {
        return $this->database()->transaction(function () use ($method, $path): string {
            $this->database()->statement("SET LOCAL lock_timeout = '2s'");
            $this->database()->table('provider_cooldowns')->insertOrIgnore(['key' => $this->key()]);
            $row = $this->database()->table('provider_cooldowns')->useWritePdo()->where('key', $this->key())->lockForUpdate()->first();
            if ($row->pending_token !== null) {
                throw new ProviderException('provider_request_pending', true, 5, deferred: true);
            }
            $deadline = $this->deadline($method, $path);
            if ($deadline > CarbonImmutable::now()->getTimestamp()) {
                throw new ProviderException('provider_cooldown', true, 5, deferred: true, retryNotBefore: $deadline);
            }
            $token = (string) Str::uuid();
            $this->database()->table('provider_cooldowns')->where('key', $this->key())->update(['pending_token' => $token, 'pending_since' => CarbonImmutable::now()]);

            return $token;
        });
    }

    /** Clear only our marker in the same commit that records the observed deadline. */
    public function finish(string $token, ?int $seconds = null, string $scope = 'account'): ?int
    {
        $deadline = $this->database()->transaction(function () use ($token, $seconds, $scope): ?int {
            $this->database()->statement("SET LOCAL lock_timeout = '2s'");
            $updated = $this->database()->table('provider_cooldowns')->where('key', $this->key())->where('pending_token', $token)->update(['pending_token' => null, 'pending_since' => null]);
            if ($updated !== 1) {
                throw new RuntimeException('Provider observation ownership changed.');
            }

            return $seconds === null ? null : $this->persist($seconds, $scope);
        });
        if ($deadline !== null) {
            $this->mirror($deadline, $scope);
        }

        return $deadline;
    }

    /** PostgreSQL is mandatory authority; Redis remains a scalar, atomic-max mirror. */
    public function extend(int $seconds, string $scope = 'account'): int
    {
        $deadline = $this->persist($seconds, $scope);
        $this->mirror($deadline, $scope);

        return $deadline;
    }

    private function persist(int $seconds, string $scope): int
    {
        $deadline = $this->validated(CarbonImmutable::now()->addSeconds(max(1, $seconds))->ceilSecond()->getTimestamp());
        $row = $this->database()->select('INSERT INTO provider_cooldowns (key, deadline) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET deadline = GREATEST(provider_cooldowns.deadline, EXCLUDED.deadline) RETURNING deadline', [$this->key($scope), $deadline], false);

        return $this->validated($row[0]->deadline);
    }

    private function mirror(int $deadline, string $scope): void
    {
        $now = CarbonImmutable::now();
        $result = Redis::connection(config('services.pixelforge.cooldown_connection'))->eval(<<<'LUA'
            local existing = redis.call('GET', KEYS[1])
            if existing and (not string.match(existing, '^%d+$') or tonumber(existing) > 253402300799) then
                return redis.error_reply('Invalid provider cooldown')
            end
            local deadline = math.max(tonumber(existing) or 0, tonumber(ARGV[1]))
            redis.call('SET', KEYS[1], string.format('%.0f', deadline), 'EX', math.max(1, deadline - tonumber(ARGV[2])))
            return deadline
            LUA, 1, $this->key($scope), $deadline, $now->getTimestamp());

        $this->validated($result);
    }

    public function key(string $scope = 'account'): string
    {
        return 'pixelforge:cooldown:'.hash('sha256', config('services.pixelforge.base_url').'|'.config('services.pixelforge.account_scope').'|'.$scope);
    }

    private function read(string $scope): int
    {
        $value = Redis::connection(config('services.pixelforge.cooldown_connection'))->get($this->key($scope));

        $stored = $this->database()->table('provider_cooldowns')->useWritePdo()->where('key', $this->key($scope))->value('deadline');

        return max($stored === null ? 0 : $this->validated($stored), $value === null ? 0 : $this->validated($value));
    }

    private function database(): ConnectionInterface
    {
        return DB::getConnections()['pixelforge_cooldowns'] ?? DB::connectUsing('pixelforge_cooldowns', DB::connection()->getConfig());
    }

    private function validated(mixed $value): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A\d{1,12}\z/', (string) $value) || (int) $value > 253402300799) {
            throw new RuntimeException('Invalid provider cooldown.');
        }

        return (int) $value;
    }
}
