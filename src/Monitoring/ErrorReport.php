<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Monitoring;

use RoayaPay\PlatformCore\Exceptions\ReportingPolicy;
use RoayaPay\PlatformCore\Exceptions\Severity;

/**
 * Immutable, already-sanitized description of a reported exception.
 * Passed to every ErrorReporter (log, Sentry, Datadog...). Internal use only:
 * file/line are included here but are never part of an API response.
 */
final readonly class ErrorReport
{
    /**
     * @param  list<string>  $chain  Classes of the previous-exception chain.
     * @param  array<string, mixed>  $context  Sanitized diagnostic context.
     */
    public function __construct(
        public string $exceptionClass,
        public string $safeMessage,
        public string $file,
        public int $line,
        public string $apiCode,
        public string $category,
        public Severity $severity,
        public ReportingPolicy $reporting,
        public bool $retryable,
        public ?string $requestId,
        public ?string $transactionId,
        public ?string $method,
        public ?string $route,
        public ?string $path,
        public string|int|null $userId,
        public string $timestamp,
        public array $chain,
        public array $context,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'exception' => $this->exceptionClass,
            'message' => $this->safeMessage,
            'file' => $this->file,
            'line' => $this->line,
            'api_code' => $this->apiCode,
            'category' => $this->category,
            'severity' => $this->severity->value,
            'reporting' => $this->reporting->value,
            'retryable' => $this->retryable,
            'request_id' => $this->requestId,
            'transaction_id' => $this->transactionId,
            'method' => $this->method,
            'route' => $this->route,
            'path' => $this->path,
            'user_id' => $this->userId,
            'timestamp' => $this->timestamp,
            'chain' => $this->chain,
            'context' => $this->context,
        ];
    }
}
