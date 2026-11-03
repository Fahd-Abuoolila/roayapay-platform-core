<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Audit\Contracts;

use RoayaPay\PlatformCore\Audit\AuditEvent;

/**
 * Persistence for audit events. Append-only by contract: implementations must
 * only INSERT and must never update or delete existing events.
 */
interface AuditStore
{
    public function append(AuditEvent $event): void;
}
