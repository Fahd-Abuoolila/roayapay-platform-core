<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Exceptions;

/**
 * Whether and where an exception is reported to the Error Monitor.
 */
enum ReportingPolicy: string
{
    /** Expected outcome (validation, business rejection): rendered, never reported. */
    case DONT_REPORT = 'dont_report';

    /** Reported to the error monitor at the exception's severity. */
    case REPORT = 'report';

    /** Reported to the security channel at the exception's severity. */
    case SECURITY = 'security';
}
