<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Exceptions;

use RoayaPay\PlatformCore\Logging\LogLevel;

enum Severity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';

    public function logLevel(): LogLevel
    {
        return match ($this) {
            self::INFO => LogLevel::INFO,
            self::WARNING => LogLevel::WARNING,
            self::ERROR => LogLevel::ERROR,
            self::CRITICAL => LogLevel::CRITICAL,
        };
    }
}
