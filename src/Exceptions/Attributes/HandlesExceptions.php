<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Exceptions\Attributes;

use Attribute;

/**
 * Declares which exception classes (or interfaces) an ExceptionMapper handles.
 *
 *     #[HandlesExceptions(AcmeTimeoutException::class, AcmeDeclinedException::class)]
 *     final class AcmeGatewayMapper implements ExceptionMapper { ... }
 *
 * The normalizer reads this attribute ONCE when the mapper is registered and
 * builds a class → mapper index, so no reflection happens per request and only
 * the relevant mapper is invoked for a given exception.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class HandlesExceptions
{
    /** @var list<class-string> */
    public array $exceptions;

    /**
     * @param  class-string  ...$exceptions
     */
    public function __construct(string ...$exceptions)
    {
        $this->exceptions = array_values($exceptions);
    }
}
