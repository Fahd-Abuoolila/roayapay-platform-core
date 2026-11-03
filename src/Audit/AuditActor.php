<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Audit;

/**
 * Who performed an audited action.
 */
final readonly class AuditActor
{
    public function __construct(public string $type, public string|int|null $id = null) {}

    public static function user(string|int $id): self
    {
        return new self('user', $id);
    }

    public static function merchant(string|int $id): self
    {
        return new self('merchant', $id);
    }

    public static function system(string $name = 'system'): self
    {
        return new self('system', $name);
    }

    public static function service(string $name): self
    {
        return new self('service', $name);
    }
}
