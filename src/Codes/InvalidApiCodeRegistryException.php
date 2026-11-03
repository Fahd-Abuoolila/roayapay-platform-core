<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Codes;

use LogicException;

/**
 * Configuration error: config/roayapay_api_codes.php is invalid.
 */
final class InvalidApiCodeRegistryException extends LogicException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("Invalid RoayaPay API code registry:\n - ".implode("\n - ", $errors));
    }
}
