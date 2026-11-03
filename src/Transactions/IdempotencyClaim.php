<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Transactions;

/**
 * The result of trying to claim an idempotency key.
 */
final readonly class IdempotencyClaim
{
    public function __construct(
        public IdempotencyState $state,
        public ?string $ownerToken = null,
        public ?StoredResult $result = null,
    ) {}

    public static function acquired(string $ownerToken): self
    {
        return new self(IdempotencyState::ACQUIRED, $ownerToken);
    }

    public static function replay(StoredResult $result): self
    {
        return new self(IdempotencyState::REPLAY, result: $result);
    }

    public static function of(IdempotencyState $state): self
    {
        return new self($state);
    }
}
