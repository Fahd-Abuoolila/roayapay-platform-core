<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Audit\Contracts;

use RoayaPay\PlatformCore\Audit\AuditActor;
use RoayaPay\PlatformCore\Audit\AuditEvent;
use RoayaPay\PlatformCore\Audit\AuditSubject;

/**
 * Records important business events. Append-only: there is intentionally no
 * update or delete operation in this contract.
 */
interface AuditTrail
{
    /**
     * @param  array<string, mixed>  $metadata  Safe metadata; sensitive keys are redacted.
     */
    public function record(string $action, AuditSubject $subject, ?AuditActor $actor = null, array $metadata = [], bool $financial = false): AuditEvent;
}
