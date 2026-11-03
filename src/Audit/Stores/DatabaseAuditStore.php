<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Audit\Stores;

use Illuminate\Database\ConnectionInterface;
use RoayaPay\PlatformCore\Audit\AuditEvent;
use RoayaPay\PlatformCore\Audit\Contracts\AuditStore;

/**
 * Append-only database store. Only INSERT statements are ever issued.
 *
 * Use the same connection as your financial tables and record financial events
 * inside the same DB transaction as the balance change, so both commit or roll
 * back together. Enforce append-only at the database level as well (revoke
 * UPDATE/DELETE on the table for the application user – see README).
 */
final readonly class DatabaseAuditStore implements AuditStore
{
    public function __construct(private ConnectionInterface $db, private string $table = 'roayapay_audit_events') {}

    public function append(AuditEvent $event): void
    {
        $row = $event->toArray();

        $this->db->table($this->table)->insert([
            ...$row,
            'metadata' => json_encode($row['metadata'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s.u'),
        ]);
    }
}
