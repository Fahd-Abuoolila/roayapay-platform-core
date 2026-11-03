<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Exceptions;

use Illuminate\Container\Container;
use Illuminate\Contracts\Support\MessageBag as MessageBagContract;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use RoayaPay\PlatformCore\Codes\ApiCodeRegistry;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use RoayaPay\PlatformCore\Support\BilingualMessage;
use RoayaPay\PlatformCore\Transactions\TransactionContext;
use RoayaPay\PlatformCore\Validation\ValidationMessageResolver;
use RuntimeException;
use Throwable;

/**
 * The single application-level exception API of RoayaPay.
 *
 *     throw RoayaPayException::business('INSUFFICIENT_BALANCE');
 *
 * Every factory returns this exact class. The registered API code decides the
 * response (code, status, message, HTTP 200/500). Category, severity,
 * reporting policy and retryability are metadata for monitoring and clients.
 *
 * What reaches the API response: the registered code/message (or the bilingual
 * override) and the explicit safe $data. What NEVER reaches it: $context,
 * the previous exception, its message, SQL, traces or file paths.
 *
 * Properties use PHP 8.4 asymmetric visibility: readable everywhere, changed
 * only through the fluent with*() methods before the exception is thrown.
 */
final class RoayaPayException extends RuntimeException
{
    public private(set) string $apiCode;

    public private(set) ErrorCategory $category;

    /** @var array<mixed>|object Safe data returned to the client in "data". */
    public private(set) array|object $data;

    public private(set) ?BilingualMessage $messageOverride = null;

    /** @var array<string, mixed> Internal diagnostic context (sanitized before logging, never rendered). */
    public private(set) array $context;

    public private(set) Severity $severity;

    public private(set) ReportingPolicy $reporting;

    public private(set) bool $retryable;

    public private(set) ?string $requestId = null;

    public private(set) ?string $transactionId = null;

    public private(set) ?MessageBag $validationErrors = null;

    /**
     * @param  array<mixed>|object  $data  Safe client-facing data.
     * @param  array{ar: string, en: string}|null  $message  Bilingual message override.
     * @param  array<string, mixed>  $context  Internal diagnostics (never rendered).
     */
    public function __construct(
        string $apiCode,
        ErrorCategory $category = ErrorCategory::BUSINESS,
        array|object $data = [],
        ?array $message = null,
        array $context = [],
        ?Severity $severity = null,
        ?ReportingPolicy $reporting = null,
        ?bool $retryable = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('RoayaPay %s exception [%s]', $category->value, $apiCode), 0, $previous);

        $this->apiCode = $apiCode;
        $this->category = $category;
        $this->data = $data;
        $this->context = $context;
        $this->severity = $severity ?? $category->defaultSeverity();
        $this->reporting = $reporting ?? $category->defaultReporting();
        $this->retryable = $retryable ?? $category->defaultRetryable();

        if ($message !== null) {
            $this->messageOverride = BilingualMessage::from($message, self::languages());
        }

        $this->captureCorrelation();
    }

    // ------------------------------------------------------------------ factories

    /**
     * A business rule rejected the operation. Known result (HTTP 200 for 200-codes), not reported.
     *
     * @param  array<mixed>|object  $data
     * @param  array{ar: string, en: string}|null  $message
     * @param  array<string, mixed>  $context
     */
    public static function business(string $code = 'BUSINESS_RULE_VIOLATION', array|object $data = [], ?array $message = null, array $context = [], ?Throwable $previous = null): self
    {
        return new self($code, ErrorCategory::BUSINESS, $data, $message, $context, previous: $previous);
    }

    /**
     * A payment-specific outcome, e.g. a confirmed decline (PAYMENT_DECLINED).
     * Use external()/PAYMENT_STATUS_UNKNOWN when the outcome is not confirmed.
     *
     * @param  array<mixed>|object  $data
     * @param  array{ar: string, en: string}|null  $message
     * @param  array<string, mixed>  $context
     */
    public static function payment(string $code = 'PAYMENT_DECLINED', array|object $data = [], ?array $message = null, array $context = [], ?Throwable $previous = null): self
    {
        return new self($code, ErrorCategory::PAYMENT, $data, $message, $context, previous: $previous);
    }

    /**
     * Validation failure → VALIDATION_ERROR, HTTP 200, one localized string in "message".
     *
     * @param  MessageBagContract|ValidatorContract|ValidationException|array<string, string|list<string>>  $errors
     * @param  array<mixed>|object  $data  Explicitly approved safe data only (default {}).
     * @param  array<string, mixed>  $context
     */
    public static function validation(MessageBagContract|ValidatorContract|ValidationException|array $errors, array|object $data = [], array $context = [], ?Throwable $previous = null): self
    {
        if ($previous === null && $errors instanceof ValidationException) {
            $previous = $errors;
        }

        $exception = new self('VALIDATION_ERROR', ErrorCategory::VALIDATION, $data, null, $context, previous: $previous);
        $exception->validationErrors = new ValidationMessageResolver()->toMessageBag($errors);

        return $exception;
    }

    /**
     * The caller is not authenticated → UNAUTHORIZED by default.
     *
     * @param  array{ar: string, en: string}|null  $message
     * @param  array<string, mixed>  $context
     */
    public static function unauthorized(string $code = 'UNAUTHORIZED', ?array $message = null, array $context = [], ?Throwable $previous = null): self
    {
        return new self($code, ErrorCategory::AUTHENTICATION, [], $message, $context, previous: $previous);
    }

    /**
     * The caller is not allowed to perform the action → FORBIDDEN by default; logged to the security channel.
     *
     * @param  array{ar: string, en: string}|null  $message
     * @param  array<string, mixed>  $context
     */
    public static function forbidden(string $code = 'FORBIDDEN', ?array $message = null, array $context = [], ?Throwable $previous = null): self
    {
        return new self($code, ErrorCategory::AUTHORIZATION, [], $message, $context, previous: $previous);
    }

    /**
     * A resource does not exist → NOT_FOUND by default (or e.g. MERCHANT_NOT_FOUND).
     *
     * @param  array<mixed>|object  $data
     * @param  array{ar: string, en: string}|null  $message
     * @param  array<string, mixed>  $context
     */
    public static function notFound(string $code = 'NOT_FOUND', array|object $data = [], ?array $message = null, array $context = [], ?Throwable $previous = null): self
    {
        return new self($code, ErrorCategory::RESOURCE, $data, $message, $context, previous: $previous);
    }

    /**
     * A third-party service failed → EXTERNAL_SERVICE_UNAVAILABLE by default; reported at ERROR.
     *
     * @param  array<string, mixed>  $context
     * @param  array{ar: string, en: string}|null  $message
     */
    public static function external(string $code = 'EXTERNAL_SERVICE_UNAVAILABLE', array $context = [], ?Throwable $previous = null, ?bool $retryable = null, ?array $message = null): self
    {
        return new self($code, ErrorCategory::EXTERNAL_SERVICE, [], $message, $context, retryable: $retryable, previous: $previous);
    }

    /**
     * A database failure → CODE_ERR (HTTP 500); reported as CRITICAL. Details stay internal.
     *
     * @param  array<string, mixed>  $context
     */
    public static function database(string $code = 'CODE_ERR', array $context = [], ?Throwable $previous = null, ?bool $retryable = null): self
    {
        return new self($code, ErrorCategory::DATABASE, [], null, $context, retryable: $retryable, previous: $previous);
    }

    /**
     * A security rejection → SECURITY_VIOLATION by default; logged to the security channel.
     *
     * @param  array{ar: string, en: string}|null  $message
     * @param  array<string, mixed>  $context
     */
    public static function security(string $code = 'SECURITY_VIOLATION', ?array $message = null, array $context = [], ?Throwable $previous = null): self
    {
        return new self($code, ErrorCategory::SECURITY, [], $message, $context, previous: $previous);
    }

    /**
     * An unexpected internal failure → CODE_ERR (HTTP 500) by default; reported at ERROR.
     *
     * @param  array<string, mixed>  $context
     * @param  array{ar: string, en: string}|null  $message
     */
    public static function internal(string $code = 'CODE_ERR', array $context = [], ?Throwable $previous = null, ?array $message = null): self
    {
        return new self($code, ErrorCategory::INTERNAL, [], $message, $context, previous: $previous);
    }

    /**
     * A runtime/environment failure → CODE_ERR (HTTP 500) by default; reported as CRITICAL.
     *
     * @param  array<string, mixed>  $context
     */
    public static function system(string $code = 'CODE_ERR', array $context = [], ?Throwable $previous = null): self
    {
        return new self($code, ErrorCategory::SYSTEM, [], null, $context, previous: $previous);
    }

    /**
     * Normalize ANY throwable (Laravel, Symfony, HTTP clients, PDO, PHP errors)
     * into a RoayaPayException, keeping the original as $previous.
     * A RoayaPayException is returned unchanged.
     */
    public static function fromThrowable(Throwable $throwable): self
    {
        $container = Container::getInstance();

        $normalizer = $container->bound(ExceptionNormalizer::class)
            ? $container->make(ExceptionNormalizer::class)
            : new ExceptionNormalizer();

        return $normalizer->normalize($throwable);
    }

    // ------------------------------------------------------------------ fluent modifiers

    /**
     * @param  array<mixed>|object  $data
     */
    public function withData(array|object $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  array{ar: string, en: string}  $message
     */
    public function withMessage(array $message): self
    {
        $this->messageOverride = BilingualMessage::from($message, self::languages());

        return $this;
    }

    /**
     * Adds internal diagnostic context (sanitized before logging, never rendered).
     *
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context): self
    {
        $this->context = [...$this->context, ...$context];

        return $this;
    }

    public function withSeverity(Severity $severity): self
    {
        $this->severity = $severity;

        return $this;
    }

    public function withReporting(ReportingPolicy $reporting): self
    {
        $this->reporting = $reporting;

        return $this;
    }

    /**
     * Marks whether the operation is SAFE to retry (i.e. no side effect happened).
     */
    public function retryable(bool $retryable = true): self
    {
        $this->retryable = $retryable;

        return $this;
    }

    public function withTransactionId(string $transactionId): self
    {
        $this->transactionId = $transactionId;

        return $this;
    }

    /**
     * Fills missing correlation IDs; never overwrites existing ones.
     */
    public function withCorrelation(?string $requestId, ?string $transactionId): self
    {
        $this->requestId ??= $requestId;
        $this->transactionId ??= $transactionId;

        return $this;
    }

    // ------------------------------------------------------------------ queries

    public function isReportable(): bool
    {
        return $this->reporting !== ReportingPolicy::DONT_REPORT;
    }

    public function hasValidationErrors(): bool
    {
        return $this->validationErrors !== null && $this->validationErrors->isNotEmpty();
    }

    /**
     * Internal diagnostic representation (for logs only – sanitize before writing).
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        return [
            'api_code' => $this->apiCode,
            'category' => $this->category->value,
            'severity' => $this->severity->value,
            'reporting' => $this->reporting->value,
            'retryable' => $this->retryable,
            'request_id' => $this->requestId,
            'transaction_id' => $this->transactionId,
            'context' => $this->context,
        ];
    }

    // ------------------------------------------------------------------ internals

    /**
     * @return list<string>
     */
    private static function languages(): array
    {
        $container = Container::getInstance();

        return $container->bound(ApiCodeRegistry::class)
            ? $container->make(ApiCodeRegistry::class)->languages
            : ['ar', 'en'];
    }

    private function captureCorrelation(): void
    {
        try {
            $container = Container::getInstance();

            if ($container->bound(RequestContext::class)) {
                $this->requestId = $container->make(RequestContext::class)->requestId;
            }

            if ($container->bound(TransactionContext::class)) {
                $this->transactionId = $container->make(TransactionContext::class)->transactionId;
            }
        } catch (Throwable) {
            // Correlation is best effort; it must never prevent the exception from being created.
        }
    }
}
