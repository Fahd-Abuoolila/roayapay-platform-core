<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Transactions\Stores;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RoayaPay\PlatformCore\Transactions\Contracts\IdempotencyStore;
use RoayaPay\PlatformCore\Transactions\IdempotencyClaim;
use RoayaPay\PlatformCore\Transactions\IdempotencyState;
use RoayaPay\PlatformCore\Transactions\StoredResult;

/**
 * Idempotency store backed by a table with a UNIQUE (scope, idempotency_key) index.
 *
 * The unique index makes claim() atomic across processes and servers: exactly
 * one concurrent INSERT wins. Publish the migration with:
 *     php artisan vendor:publish --tag=roayapay-migrations
 */
final readonly class DatabaseIdempotencyStore implements IdempotencyStore
{
    private const string IN_PROGRESS = 'in_progress';

    private const string COMPLETED = 'completed';

    private const string UNKNOWN = 'unknown';

    public function __construct(private ConnectionInterface $db, private string $table = 'roayapay_idempotency_keys') {}

    public function claim(string $scope, string $key, string $fingerprint, int $lockTtlSeconds, int $retentionSeconds): IdempotencyClaim
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $now = CarbonImmutable::now('UTC');
            $owner = (string) Str::uuid7();

            try {
                $this->db->table($this->table)->insert([
                    'scope' => $scope,
                    'idempotency_key' => $key,
                    'fingerprint' => $fingerprint,
                    'status' => self::IN_PROGRESS,
                    'owner_token' => $owner,
                    'result' => null,
                    'locked_until' => $now->addSeconds($lockTtlSeconds),
                    'expires_at' => $now->addSeconds($retentionSeconds),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return IdempotencyClaim::acquired($owner);
            } catch (UniqueConstraintViolationException) {
                $row = $this->find($scope, $key);

                if ($row === null) {
                    continue; // Removed between INSERT and SELECT – try once more.
                }

                if (CarbonImmutable::parse($row->expires_at, 'UTC')->lt($now) && $row->status === self::COMPLETED) {
                    // Retention elapsed: the key may be reused.
                    $this->db->table($this->table)->where('id', $row->id)->where('status', self::COMPLETED)->delete();

                    continue;
                }

                return $this->evaluate($row, $fingerprint, $now);
            }
        }

        return IdempotencyClaim::of(IdempotencyState::IN_PROGRESS);
    }

    public function complete(string $scope, string $key, string $ownerToken, StoredResult $result, int $retentionSeconds): void
    {
        $now = CarbonImmutable::now('UTC');

        $this->owned($scope, $key, $ownerToken)->update([
            'status' => self::COMPLETED,
            'result' => json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'expires_at' => $now->addSeconds($retentionSeconds),
            'updated_at' => $now,
        ]);
    }

    public function markOutcomeUnknown(string $scope, string $key, string $ownerToken): void
    {
        $this->owned($scope, $key, $ownerToken)->update([
            'status' => self::UNKNOWN,
            'updated_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    public function release(string $scope, string $key, string $ownerToken): void
    {
        $this->owned($scope, $key, $ownerToken)->delete();
    }

    public function forget(string $scope, string $key): void
    {
        $this->db->table($this->table)->where('scope', $scope)->where('idempotency_key', $key)->delete();
    }

    private function evaluate(object $row, string $fingerprint, CarbonImmutable $now): IdempotencyClaim
    {
        if (! hash_equals((string) $row->fingerprint, $fingerprint)) {
            return IdempotencyClaim::of(IdempotencyState::CONFLICT);
        }

        return match ($row->status) {
            self::COMPLETED => IdempotencyClaim::replay(StoredResult::fromArray(json_decode((string) $row->result, true, flags: JSON_THROW_ON_ERROR))),
            self::UNKNOWN => IdempotencyClaim::of(IdempotencyState::OUTCOME_UNKNOWN),
            default => $this->inProgress($row, $now),
        };
    }

    private function inProgress(object $row, CarbonImmutable $now): IdempotencyClaim
    {
        if (CarbonImmutable::parse($row->locked_until, 'UTC')->gt($now)) {
            return IdempotencyClaim::of(IdempotencyState::IN_PROGRESS);
        }

        // The owner died or exceeded its lock. It may have reached the provider: never re-acquire silently.
        $this->db->table($this->table)
            ->where('id', $row->id)
            ->where('status', self::IN_PROGRESS)
            ->update(['status' => self::UNKNOWN, 'updated_at' => $now]);

        return IdempotencyClaim::of(IdempotencyState::OUTCOME_UNKNOWN);
    }

    private function find(string $scope, string $key): ?object
    {
        return $this->db->table($this->table)->where('scope', $scope)->where('idempotency_key', $key)->first();
    }

    private function owned(string $scope, string $key, string $ownerToken): \Illuminate\Database\Query\Builder
    {
        return $this->db->table($this->table)
            ->where('scope', $scope)
            ->where('idempotency_key', $key)
            ->where('owner_token', $ownerToken)
            ->where('status', self::IN_PROGRESS);
    }
}
