<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Logging;

/**
 * RoayaPay standard log levels.
 *
 * SECURITY is written to the security channel at PSR "warning" level and
 * tagged with roayapay.level = SECURITY so it can be routed and alerted on.
 */
enum LogLevel: string
{
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
    case CRITICAL = 'CRITICAL';
    case SECURITY = 'SECURITY';

    public function psrLevel(): string
    {
        return match ($this) {
            self::INFO => 'info',
            self::WARNING, self::SECURITY => 'warning',
            self::ERROR => 'error',
            self::CRITICAL => 'critical',
        };
    }
}
