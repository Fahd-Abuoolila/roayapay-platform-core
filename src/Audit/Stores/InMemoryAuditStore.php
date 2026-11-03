<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Audit\Stores;

use RoayaPay\PlatformCore\Audit\AuditEvent;
use RoayaPay\PlatformCore\Audit\Contracts\AuditStore;

/**
 * Append-only in-memory store for tests. Events can be read but never changed.
 */
final class InMemoryAuditStore implements AuditStore
{
    /** @var list<AuditEvent> */
    private array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<AuditEvent>
     */
    public function events(): array
    {
        return $this->events;
    }
}
