<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Fixtures;

/**
 * Test-only counter of how many times the idempotent operation really executed.
 * (Lives in tests/, so it is excluded from the package's no-static-state rule.)
 */
final class PaymentCounter
{
    public static int $calls = 0;
}
