<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Transactions\Stores;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use RoayaPay\PlatformCore\Transactions\Contracts\IdempotencyStore;
use RoayaPay\PlatformCore\Transactions\IdempotencyClaim;
use RoayaPay\PlatformCore\Transactions\IdempotencyState;
use RoayaPay\PlatformCore\Transactions\StoredResult;

/**
 * Idempotency store backed by a Laravel cache store with atomic add() and locks
 * (redis, memcached, database, dynamodb). Do not use the "file" or "array"
 * stores across multiple servers.
 *
 * Note: cache stores may evict entries under memory pressure. For financial
 * operations prefer DatabaseIdempotencyStore (durable) or a Redis instance with
 * a no-eviction policy.
 */
final readonly class CacheIdempotencyStore implements IdempotencyStore
{
    public function __construct(private Repository $cache, private string $prefix = 'roayapay:idempotency:') {}

    public function claim(string $scope, string $key, string $fingerprint, int $lockTtlSeconds, int $retentionSeconds): IdempotencyClaim
    {
        $owner = (string) Str::uuid7();
        $cacheKey = $this->key($scope, $key);

        $record = [
            'fingerprint' => $fingerprint,
            'status' => 'in_progress',
            'owner' => $owner,
            'locked_until' => Carbon::now()->getTimestamp() + $lockTtlSeconds,
            'result' => null,
        ];

        if ($this->cache->add($cacheKey, $record, $retentionSeconds)) {
            return IdempotencyClaim::acquired($owner);
        }

        return $this->withLock($cacheKey, function () use ($cacheKey, $fingerprint): IdempotencyClaim {
            $existing = $this->cache->get($cacheKey);

            if (! is_array($existing)) {
                // Expired between add() and get(); the caller may retry the request.
                return IdempotencyClaim::of(IdempotencyState::IN_PROGRESS);
            }

            if (! hash_equals((string) $existing['fingerprint'], $fingerprint)) {
                return IdempotencyClaim::of(IdempotencyState::CONFLICT);
            }

            return match ($existing['status']) {
                'completed' => IdempotencyClaim::replay(StoredResult::fromArray($existing['result'])),
                'unknown' => IdempotencyClaim::of(IdempotencyState::OUTCOME_UNKNOWN),
                default => $existing['locked_until'] > Carbon::now()->getTimestamp()
                    ? IdempotencyClaim::of(IdempotencyState::IN_PROGRESS)
                    : $this->expire($cacheKey, $existing),
            };
        });
    }

    public function complete(string $scope, string $key, string $ownerToken, StoredResult $result, int $retentionSeconds): void
    {
        $this->updateOwned($scope, $key, $ownerToken, fn (array $r): array => [...$r, 'status' => 'completed', 'result' => $result->toArray()], $retentionSeconds);
    }

    public function markOutcomeUnknown(string $scope, string $key, string $ownerToken): void
    {
        $this->updateOwned($scope, $key, $ownerToken, fn (array $r): array => [...$r, 'status' => 'unknown']);
    }

    public function release(string $scope, string $key, string $ownerToken): void
    {
        $cacheKey = $this->key($scope, $key);

        $this->withLock($cacheKey, function () use ($cacheKey, $ownerToken): void {
            $existing = $this->cache->get($cacheKey);

            if (is_array($existing) && $existing['owner'] === $ownerToken && $existing['status'] === 'in_progress') {
                $this->cache->forget($cacheKey);
            }
        });
    }

    public function forget(string $scope, string $key): void
    {
        $this->cache->forget($this->key($scope, $key));
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    private function expire(string $cacheKey, array $existing): IdempotencyClaim
    {
        $this->cache->put($cacheKey, [...$existing, 'status' => 'unknown'], 86400);

        return IdempotencyClaim::of(IdempotencyState::OUTCOME_UNKNOWN);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function updateOwned(string $scope, string $key, string $ownerToken, callable $change, ?int $ttl = null): void
    {
        $cacheKey = $this->key($scope, $key);

        $this->withLock($cacheKey, function () use ($cacheKey, $ownerToken, $change, $ttl): void {
            $existing = $this->cache->get($cacheKey);

            if (is_array($existing) && $existing['owner'] === $ownerToken && $existing['status'] === 'in_progress') {
                $this->cache->put($cacheKey, $change($existing), $ttl ?? 86400);
            }
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(string $cacheKey, callable $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The idempotency cache store must support atomic locks.');
        }

        return $store->lock($cacheKey.':lock', 10)->block(5, $callback);
    }

    private function key(string $scope, string $key): string
    {
        return $this->prefix.hash('sha256', $scope."\0".$key);
    }
}
