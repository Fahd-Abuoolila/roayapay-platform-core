<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Audit;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * An immutable business audit event. Separate from technical error logs.
 *
 * $action uses dotted lowercase names: payment.created, payment.completed,
 * payment.failed, merchant.updated, user.permission_changed,
 * balance.adjusted, provider.configuration_changed.
 */
final readonly class AuditEvent
{
    public const string ACTION_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    /**
     * @param  array<string, mixed>  $metadata  Already sanitized.
     */
    public function __construct(
        public string $id,
        public string $action,
        public AuditActor $actor,
        public AuditSubject $subject,
        public array $metadata,
        public bool $financial,
        public DateTimeImmutable $occurredAt,
        public ?string $requestId,
        public ?string $transactionId,
    ) {
        if (! preg_match(self::ACTION_PATTERN, $action)) {
            throw new InvalidArgumentException(sprintf('Invalid audit action [%s]; use dotted lowercase like "payment.created".', $action));
        }
    }

    /**
     * Deterministic SHA-256 of the event content (tamper evidence per record).
     */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->toArray(includeHash: false), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeHash = true): array
    {
        $data = [
            'id' => $this->id,
            'action' => $this->action,
            'actor_type' => $this->actor->type,
            'actor_id' => $this->actor->id === null ? null : (string) $this->actor->id,
            'subject_type' => $this->subject->type,
            'subject_id' => (string) $this->subject->id,
            'metadata' => $this->metadata,
            'financial' => $this->financial,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'request_id' => $this->requestId,
            'transaction_id' => $this->transactionId,
        ];

        return $includeHash ? [...$data, 'hash' => $this->hash()] : $data;
    }
}
