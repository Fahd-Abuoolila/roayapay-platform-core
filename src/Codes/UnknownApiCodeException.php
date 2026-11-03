<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Codes;

use LogicException;

/**
 * Programming error: a symbolic code that is not registered in config/roayapay_api_codes.php.
 *
 * This is a package-internal developer error (like a TypeError), not an
 * application-level error API. Application code throws RoayaPayException only.
 */
final class UnknownApiCodeException extends LogicException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf(
            'RoayaPay API code [%s] is not registered. Add it to config/roayapay_api_codes.php.',
            $key,
        ));
    }
}
