<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Fixtures;

use RoayaPay\PlatformCore\Exceptions\Attributes\HandlesExceptions;
use RoayaPay\PlatformCore\Exceptions\Contracts\ExceptionMapper;
use RoayaPay\PlatformCore\Exceptions\RoayaPayException;
use Throwable;

#[HandlesExceptions(AcmeDeclinedException::class, AcmeTimeoutException::class)]
final class AcmeGatewayMapper implements ExceptionMapper
{
    public function map(Throwable $exception): ?RoayaPayException
    {
        return match (true) {
            $exception instanceof AcmeDeclinedException => RoayaPayException::payment('PAYMENT_DECLINED', context: ['reason' => $exception->reason]),
            $exception instanceof AcmeTimeoutException => RoayaPayException::external('PAYMENT_STATUS_UNKNOWN', retryable: false),
            default => null,
        };
    }
}
