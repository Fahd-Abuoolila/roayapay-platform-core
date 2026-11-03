<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Monitoring\Contracts;

use RoayaPay\PlatformCore\Monitoring\ErrorReport;
use Throwable;

/**
 * A destination for reported exceptions (log channel, Sentry, Bugsnag, Datadog...).
 *
 * Register implementations in config('roayapay.monitoring.reporters').
 * The report is already sanitized. $original is provided for SDKs that need
 * the raw exception (e.g. stack traces in Sentry); never send it to clients.
 */
interface ErrorReporter
{
    public function report(ErrorReport $report, Throwable $original): void;
}
