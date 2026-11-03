<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Monitoring;

use RoayaPay\PlatformCore\Exceptions\ReportingPolicy;
use RoayaPay\PlatformCore\Logging\LogLevel;
use RoayaPay\PlatformCore\Logging\RoayaPayLogger;
use RoayaPay\PlatformCore\Monitoring\Contracts\ErrorReporter;
use Throwable;

/**
 * Default reporter: writes the sanitized report to the RoayaPay log channel
 * (or the security channel for security-policy exceptions).
 */
final readonly class LogErrorReporter implements ErrorReporter
{
    public function __construct(private RoayaPayLogger $logger) {}

    public function report(ErrorReport $report, Throwable $original): void
    {
        $level = $report->reporting === ReportingPolicy::SECURITY
            ? LogLevel::SECURITY
            : $report->severity->logLevel();

        $this->logger->log($level, sprintf('roayapay.exception: %s [%s]', $report->apiCode, $report->exceptionClass), [
            'error' => $report->toArray(),
            'request_id' => $report->requestId,
            'transaction_id' => $report->transactionId,
        ]);
    }
}
