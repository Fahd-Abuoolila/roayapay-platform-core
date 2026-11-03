<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Audit\Stores;

use Illuminate\Log\LogManager;
use RoayaPay\PlatformCore\Audit\AuditEvent;
use RoayaPay\PlatformCore\Audit\Contracts\AuditStore;

/**
 * Writes audit events to a dedicated, append-only log channel (e.g. a
 * write-once bucket or SIEM). Keep this channel separate from error logs.
 */
final readonly class LogAuditStore implements AuditStore
{
    public function __construct(private LogManager $log, private ?string $channel = null) {}

    public function append(AuditEvent $event): void
    {
        $logger = $this->channel === null ? $this->log->channel() : $this->log->channel($this->channel);

        $logger->info('roayapay.audit: '.$event->action, ['audit' => $event->toArray()]);
    }
}
