<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Transactions\Contracts;

use RoayaPay\PlatformCore\Transactions\IdempotencyClaim;
use RoayaPay\PlatformCore\Transactions\StoredResult;

/**
 * Storage for idempotency keys. Implement it on your own schema if needed.
 *
 * Contract requirements for implementations:
 *  - claim() MUST be atomic: of N concurrent claims for the same (scope, key),
 *    exactly one returns ACQUIRED (use a unique index or an atomic cache add).
 *  - complete()/markOutcomeUnknown()/release() only act when $ownerToken matches
 *    the token returned by claim(), so a stale worker cannot overwrite a result.
 *  - An in-progress claim whose lock expired MUST be reported as
 *    OUTCOME_UNKNOWN, never silently re-acquired: the first attempt may have
 *    reached the payment provider.
 */
interface IdempotencyStore
{
    public function claim(string $scope, string $key, string $fingerprint, int $lockTtlSeconds, int $retentionSeconds): IdempotencyClaim;

    public function complete(string $scope, string $key, string $ownerToken, StoredResult $result, int $retentionSeconds): void;

    public function markOutcomeUnknown(string $scope, string $key, string $ownerToken): void;

    /**
     * Frees the key because the operation definitely had no side effects (e.g. it was rejected before execution).
     */
    public function release(string $scope, string $key, string $ownerToken): void;

    /**
     * Administrative: remove a key after its real outcome has been verified/reconciled.
     */
    public function forget(string $scope, string $key): void;
}
