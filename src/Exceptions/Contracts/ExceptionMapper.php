<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Exceptions\Contracts;

use RoayaPay\PlatformCore\Exceptions\RoayaPayException;
use Throwable;

/**
 * Converts a third-party / framework exception into a RoayaPayException.
 *
 * Annotate implementations with #[HandlesExceptions(...)]. Return null to let
 * the next mapper (or the built-in rules) handle the exception.
 */
interface ExceptionMapper
{
    public function map(Throwable $exception): ?RoayaPayException;
}
