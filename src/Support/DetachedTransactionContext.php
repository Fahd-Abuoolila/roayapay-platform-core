<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Support;

use RoayaPay\PlatformCore\Transactions\TransactionContext;

/**
 * Container-scoped holder for the transaction context used outside HTTP requests.
 *
 * @internal
 */
final class DetachedTransactionContext
{
    public readonly TransactionContext $instance;

    public function __construct()
    {
        $this->instance = new TransactionContext();
    }
}
