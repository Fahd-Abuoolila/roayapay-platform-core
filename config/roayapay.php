<?php

declare(strict_types=1);

use RoayaPay\PlatformCore\Monitoring\LogErrorReporter;

return [

    /*
    |--------------------------------------------------------------------------
    | API detection
    |--------------------------------------------------------------------------
    | A request is treated as a RoayaPay API request (standard four-key
    | responses, RoayaPay exception rendering) when it passed through one of the
    | package middleware, OR its path matches one of these patterns. Web routes
    | are never affected.
    */
    'api' => [
        'paths' => ['api/*'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mandatory request language
    |--------------------------------------------------------------------------
    | 'fallback' is used ONLY to word responses produced before a language was
    | validated (e.g. the "language required" rejection itself, or a 404 for an
    | unknown API route). Valid requests never fall back silently.
    */
    'language' => [
        'header' => 'Accept-Language',
        'supported' => ['ar', 'en'],
        'fallback' => 'en',
    ],

    /*
    |--------------------------------------------------------------------------
    | Request ID
    |--------------------------------------------------------------------------
    */
    'request_id' => [
        'header' => 'X-Request-Id',
        // Accept an incoming ID (e.g. from your gateway) when it matches the pattern.
        'trust_incoming' => true,
        'pattern' => '/^[A-Za-z0-9][A-Za-z0-9._:\-]{7,127}$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    | null uses the application's default channel.
    */
    'logging' => [
        'channel' => null,
        'security_channel' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanitizer – keys and patterns that are always redacted in logs
    |--------------------------------------------------------------------------
    */
    'sanitizer' => [
        'mask' => '[REDACTED]',
        // Extra normalized key names to redact (lowercase, no separators), e.g. 'nationalid'.
        'extra_keys' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error monitoring
    |--------------------------------------------------------------------------
    | Reporters implement RoayaPay\PlatformCore\Monitoring\Contracts\ErrorReporter.
    | Add your Sentry / Bugsnag / Datadog adapter here.
    */
    'monitoring' => [
        'enabled' => true,
        'reporters' => [
            LogErrorReporter::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exceptions
    |--------------------------------------------------------------------------
    | auto_register: hook the RoayaPay renderer/reporter into Laravel's exception
    |                handler automatically (API requests only).
    | map:           ExceptionClass::class => 'API_CODE'
    |                or ExceptionClass::class => ['code' => 'API_CODE', 'category' => 'PAYMENT']
    | mappers:       classes implementing ExceptionMapper and annotated with
    |                #[HandlesExceptions(...)].
    | model_not_found: Model::class => 'API_CODE' (e.g. Merchant::class => 'MERCHANT_NOT_FOUND')
    */
    'exceptions' => [
        'auto_register' => true,
        'map' => [],
        'mappers' => [],
        'model_not_found' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    | localize_framework_validator: make Validator::make(), $request->validate()
    |   and FormRequests produce messages in the validated request language
    |   (API requests only) by giving each validator a language-bound translator.
    | all_messages_per_field: false = one message per field.
    */
    'validation' => [
        'localize_framework_validator' => true,
        'all_messages_per_field' => false,
        'separator' => "\n",
    ],

    /*
    |--------------------------------------------------------------------------
    | Response enforcement (runtime fallback – see static scanner for CI)
    |--------------------------------------------------------------------------
    | mode: 'replace' = log + replace unapproved JSON with RESPONSE_CONTRACT_VIOLATION
    |       'log'     = log only (useful while migrating legacy endpoints)
    */
    'enforcement' => [
        'mode' => 'replace',
        'replacement_code' => 'RESPONSE_CONTRACT_VIOLATION',
        'allowed_routes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    | store: 'database' | 'log' | a class implementing Audit\Contracts\AuditStore
    */
    'audit' => [
        'store' => 'database',
        'connection' => null,
        'table' => 'roayapay_audit_events',
        'log_channel' => null,
        // Non-financial audit failures are logged and swallowed; financial ones always throw.
        'swallow_non_financial_failures' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    | store: 'database' | 'cache' | a class implementing Transactions\Contracts\IdempotencyStore
    */
    'idempotency' => [
        'header' => 'Idempotency-Key',
        'store' => 'database',
        'connection' => null,
        'table' => 'roayapay_idempotency_keys',
        'cache_store' => null,
        // Seconds an in-progress claim is considered alive before it becomes "outcome unknown".
        'lock_ttl' => 300,
        // Seconds a completed result is kept for replay.
        'retention' => 86400,
    ],

];
