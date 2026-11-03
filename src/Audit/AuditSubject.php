<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Audit;

/**
 * What an audited action was performed on (e.g. payment #123).
 */
final readonly class AuditSubject
{
    public function __construct(public string $type, public string|int $id) {}

    public static function of(string $type, string|int $id): self
    {
        return new self($type, $id);
    }
}
