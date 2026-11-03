<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Fixtures;

final class AcmeDeclinedException extends AcmeException
{
    public function __construct(public readonly string $reason = 'do_not_honor')
    {
        parent::__construct('Card declined: '.$reason);
    }
}
