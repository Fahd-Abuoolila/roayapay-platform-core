<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Transactions\Contracts;

use RoayaPay\PlatformCore\Transactions\TransactionStatus;

/**
 * Implemented by the APPLICATION to answer "what really happened to this
 * transaction?" after a timeout or an OUTCOME_UNKNOWN result – typically by
 * reading your own transactions table and, if still pending, asking the
 * payment provider's status API.
 *
 * The package never decides a transaction failed just because a client or a
 * provider call timed out.
 */
interface TransactionStatusResolver
{
    public function resolve(string $transactionId): TransactionStatus;
}
