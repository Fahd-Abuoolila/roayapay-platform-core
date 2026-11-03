<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Exceptions;

/**
 * What kind of failure an exception represents.
 *
 * The category drives DEFAULTS only (default code, severity, reporting policy,
 * retryability). It never determines the HTTP status: the registered API code
 * definition alone decides code, status, message and HTTP status.
 */
enum ErrorCategory: string
{
    /** The client sent invalid input. Known result. */
    case VALIDATION = 'VALIDATION';

    /** The caller is not authenticated (missing/invalid credentials). Known result. */
    case AUTHENTICATION = 'AUTHENTICATION';

    /** The caller is authenticated but not allowed to perform the action. Known result. */
    case AUTHORIZATION = 'AUTHORIZATION';

    /** A requested resource or endpoint does not exist. Known result. */
    case RESOURCE = 'RESOURCE';

    /** A business rule rejected the operation (e.g. insufficient balance). Known result. */
    case BUSINESS = 'BUSINESS';

    /** A payment-specific result such as a confirmed decline. Known result unless the code says otherwise. */
    case PAYMENT = 'PAYMENT';

    /** A database failure (query error, deadlock, lost connection). Unexpected. */
    case DATABASE = 'DATABASE';

    /** A third-party service failed, timed out or rejected the call. */
    case EXTERNAL_SERVICE = 'EXTERNAL_SERVICE';

    /** A security-relevant rejection (bad signature, CSRF, tampering, throttling). */
    case SECURITY = 'SECURITY';

    /** An unexpected failure in application logic (bugs, unmapped exceptions). */
    case INTERNAL = 'INTERNAL';

    /** A runtime/environment failure (misconfiguration, missing service, cache/queue down). */
    case SYSTEM = 'SYSTEM';

    public function defaultCode(): string
    {
        return match ($this) {
            self::VALIDATION => 'VALIDATION_ERROR',
            self::AUTHENTICATION => 'UNAUTHORIZED',
            self::AUTHORIZATION => 'FORBIDDEN',
            self::RESOURCE => 'NOT_FOUND',
            self::BUSINESS => 'BUSINESS_RULE_VIOLATION',
            self::PAYMENT => 'PAYMENT_DECLINED',
            self::EXTERNAL_SERVICE => 'EXTERNAL_SERVICE_UNAVAILABLE',
            self::SECURITY => 'SECURITY_VIOLATION',
            self::DATABASE, self::INTERNAL, self::SYSTEM => 'CODE_ERR',
        };
    }

    public function defaultSeverity(): Severity
    {
        return match ($this) {
            self::VALIDATION, self::AUTHENTICATION, self::RESOURCE, self::BUSINESS => Severity::INFO,
            self::AUTHORIZATION, self::PAYMENT, self::SECURITY => Severity::WARNING,
            self::EXTERNAL_SERVICE, self::INTERNAL => Severity::ERROR,
            self::DATABASE, self::SYSTEM => Severity::CRITICAL,
        };
    }

    public function defaultReporting(): ReportingPolicy
    {
        return match ($this) {
            self::VALIDATION, self::AUTHENTICATION, self::RESOURCE, self::BUSINESS => ReportingPolicy::DONT_REPORT,
            self::AUTHORIZATION, self::SECURITY => ReportingPolicy::SECURITY,
            self::PAYMENT, self::DATABASE, self::EXTERNAL_SERVICE, self::INTERNAL, self::SYSTEM => ReportingPolicy::REPORT,
        };
    }

    /**
     * Whether retrying the same request is safe by default.
     * Financial operations must still be protected by an idempotency key.
     */
    public function defaultRetryable(): bool
    {
        return match ($this) {
            self::DATABASE => true,
            default => false,
        };
    }

    public function isExpectedOutcome(): bool
    {
        return $this->defaultReporting() !== ReportingPolicy::REPORT;
    }
}
