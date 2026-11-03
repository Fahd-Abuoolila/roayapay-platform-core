<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use RoayaPay\PlatformCore\Audit\Contracts\AuditStore;
use RoayaPay\PlatformCore\Audit\Contracts\AuditTrail as AuditTrailContract;
use RoayaPay\PlatformCore\Logging\RoayaPayLogger;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use RoayaPay\PlatformCore\Support\Sanitizer;
use RoayaPay\PlatformCore\Transactions\TransactionContext;
use Throwable;

/**
 * Default AuditTrail implementation.
 *
 *     $audit->record('payment.completed', AuditSubject::of('payment', $payment->id),
 *         AuditActor::merchant($merchant->id), ['amount' => '150.00', 'currency' => 'EGP'], financial: true);
 *
 * - Enriches events with request ID, transaction ID, a UUIDv7 and a UTC timestamp.
 * - Sanitizes metadata (no passwords, OTPs, tokens, card numbers, secrets).
 * - Resolves the actor from the authenticated user when none is given.
 * - Financial events are never dropped: a storage failure is re-thrown so the
 *   surrounding DB transaction can roll back. Non-financial failures are logged
 *   and swallowed when configured.
 */
final readonly class AuditTrail implements AuditTrailContract
{
    public function __construct(
        private AuditStore $store,
        private Container $container,
        private Sanitizer $sanitizer,
        private RoayaPayLogger $logger,
        private bool $swallowNonFinancialFailures = true,
    ) {}

    public function record(string $action, AuditSubject $subject, ?AuditActor $actor = null, array $metadata = [], bool $financial = false): AuditEvent
    {
        $event = new AuditEvent(
            id: (string) Str::uuid7(),
            action: $action,
            actor: $actor ?? $this->currentActor(),
            subject: $subject,
            metadata: $this->sanitizer->sanitize($metadata),
            financial: $financial,
            occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            requestId: $this->container->make(RequestContext::class)->requestId,
            transactionId: $this->container->make(TransactionContext::class)->transactionId,
        );

        try {
            $this->store->append($event);
        } catch (Throwable $failure) {
            if ($financial || ! $this->swallowNonFinancialFailures) {
                throw $failure;
            }

            $this->logger->error('roayapay.audit.write_failed', [
                'action' => $action,
                'audit_event_id' => $event->id,
                'exception' => $failure::class,
            ]);
        }

        return $event;
    }

    private function currentActor(): AuditActor
    {
        try {
            $guard = $this->container->make('auth')->guard();

            if (method_exists($guard, 'hasUser') && $guard->hasUser() && $guard->id() !== null) {
                return AuditActor::user($guard->id());
            }
        } catch (Throwable) {
            // Fall through to system actor.
        }

        return AuditActor::system();
    }
}
