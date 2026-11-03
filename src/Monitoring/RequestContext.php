<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Monitoring;

use LogicException;

/**
 * Request-scoped RoayaPay context: request ID, validated language and API marker.
 *
 * One instance exists per HTTP request object (it is stored on the request's
 * attribute bag), so state can never leak between requests in Octane,
 * FrankenPHP or any long-running worker. Outside HTTP (console, queue) a
 * container-scoped instance is used, which Laravel flushes per job.
 *
 * Values are write-once: a middleware cannot silently change them mid-request.
 * Uses PHP 8.4 asymmetric visibility: publicly readable, privately writable.
 */
final class RequestContext
{
    public const string ATTRIBUTE = '_roayapay.context';

    public private(set) ?string $requestId = null;

    public private(set) ?string $language = null;

    public private(set) bool $isApi = false;

    public function setRequestId(string $requestId): void
    {
        $this->assertWritable('requestId', $this->requestId, $requestId);
        $this->requestId = $requestId;
    }

    public function setLanguage(string $language): void
    {
        $this->assertWritable('language', $this->language, $language);
        $this->language = $language;
    }

    public function markAsApi(): void
    {
        $this->isApi = true;
    }

    public function hasLanguage(): bool
    {
        return $this->language !== null;
    }

    private function assertWritable(string $name, ?string $current, string $new): void
    {
        if ($current !== null && $current !== $new) {
            throw new LogicException(sprintf('RoayaPay request context "%s" is already set for this request.', $name));
        }
    }
}
