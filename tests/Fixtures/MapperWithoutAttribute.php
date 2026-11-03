<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Fixtures;

final class MapperWithoutAttribute implements \RoayaPay\PlatformCore\Exceptions\Contracts\ExceptionMapper
{
    public function map(\Throwable $exception): ?\RoayaPay\PlatformCore\Exceptions\RoayaPayException
    {
        return null;
    }
}
