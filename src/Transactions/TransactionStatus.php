<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Transactions;

enum TransactionStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    /** Not yet determinable (e.g. provider unreachable). Do not retry the charge; check again later. */
    case UNKNOWN = 'unknown';

    public function isFinal(): bool
    {
        return $this === self::COMPLETED || $this === self::FAILED;
    }
}
