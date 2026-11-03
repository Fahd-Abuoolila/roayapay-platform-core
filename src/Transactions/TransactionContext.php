<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Transactions;

use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Request-scoped transaction identity: transaction ID and idempotency key.
 *
 * Correlates with the request ID in logs, error reports and audit records.
 * It does NOT model your financial transaction – your application keeps its
 * own schema; this only carries identifiers for correlation.
 *
 *     $transactionId = app(TransactionContext::class)->begin();          // generates a UUIDv7
 *     $transactionId = app(TransactionContext::class)->begin($payment->reference);
 */
final class TransactionContext
{
    public const string ATTRIBUTE = '_roayapay.transaction';

    public const string ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\-]{2,127}$/';

    public private(set) ?string $transactionId = null;

    public private(set) ?string $idempotencyKey = null;

    /**
     * Starts (or adopts) the transaction identity for this request. Set-once.
     */
    public function begin(?string $transactionId = null): string
    {
        $transactionId ??= (string) Str::uuid7();

        if (! preg_match(self::ID_PATTERN, $transactionId)) {
            throw new InvalidArgumentException('Invalid transaction ID format.');
        }

        if ($this->transactionId !== null && $this->transactionId !== $transactionId) {
            throw new LogicException('A transaction ID is already set for this request.');
        }

        return $this->transactionId = $transactionId;
    }

    public function setIdempotencyKey(string $key): void
    {
        if ($this->idempotencyKey !== null && $this->idempotencyKey !== $key) {
            throw new LogicException('An idempotency key is already set for this request.');
        }

        $this->idempotencyKey = $key;
    }
}
