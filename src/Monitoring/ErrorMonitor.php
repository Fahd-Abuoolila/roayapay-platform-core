<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Monitoring;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use RoayaPay\PlatformCore\Exceptions\RoayaPayException;
use RoayaPay\PlatformCore\Monitoring\Contracts\ErrorReporter;
use RoayaPay\PlatformCore\Support\Sanitizer;
use RoayaPay\PlatformCore\Transactions\TransactionContext;
use Throwable;
use WeakMap;

/**
 * Centralized error monitor.
 *
 * - Applies the exception's reporting policy (expected business outcomes are not reported).
 * - Builds a sanitized ErrorReport with correlation data (request ID, transaction ID,
 *   method, route, user ID, timestamp) and file/line for INTERNAL logs only.
 * - Fans out to every configured ErrorReporter; a failing reporter never breaks
 *   the request and never stops the other reporters.
 * - Reports each exception at most once, even when it passes through the
 *   exception handler's report() and render() paths.
 *
 * Bound as a scoped service: the de-duplication map lives for one request/job only.
 */
final class ErrorMonitor
{
    private const int MAX_MESSAGE_LENGTH = 1000;

    /** @var WeakMap<Throwable, true> */
    private WeakMap $reported;

    /**
     * @param  iterable<ErrorReporter>  $reporters
     */
    public function __construct(
        private readonly Container $container,
        private readonly Sanitizer $sanitizer,
        private readonly iterable $reporters,
        private readonly bool $enabled = true,
    ) {
        $this->reported = new WeakMap();
    }

    /**
     * Report a normalized exception according to its reporting policy.
     *
     * @return bool Whether a report was emitted.
     */
    public function capture(RoayaPayException $exception, ?Throwable $original = null): bool
    {
        if (! $this->enabled || ! $exception->isReportable()) {
            return false;
        }

        $original ??= $exception->getPrevious() ?? $exception;

        if ($this->wasReported($original) || $this->wasReported($exception)) {
            return false;
        }

        $this->reported[$original] = true;
        $this->reported[$exception] = true;

        try {
            $report = $this->buildReport($exception, $original);
        } catch (Throwable $failure) {
            error_log('[roayapay] error monitor could not build a report: '.$failure::class);

            return false;
        }

        foreach ($this->reporters as $reporter) {
            try {
                $reporter->report($report, $original);
            } catch (Throwable $failure) {
                error_log(sprintf('[roayapay] error reporter %s failed: %s', $reporter::class, $failure::class));
            }
        }

        return true;
    }

    public function wasReported(Throwable $exception): bool
    {
        return isset($this->reported[$exception]);
    }

    public function buildReport(RoayaPayException $exception, Throwable $original): ErrorReport
    {
        $request = $this->currentRequest();
        $requestContext = $this->container->make(RequestContext::class);
        $transaction = $this->container->make(TransactionContext::class);

        $route = $request?->route();

        return new ErrorReport(
            exceptionClass: $original::class,
            safeMessage: $this->safeMessage($original),
            file: $original->getFile(),
            line: $original->getLine(),
            apiCode: $exception->apiCode,
            category: $exception->category->value,
            severity: $exception->severity,
            reporting: $exception->reporting,
            retryable: $exception->retryable,
            requestId: $exception->requestId ?? $requestContext->requestId,
            transactionId: $exception->transactionId ?? $transaction->transactionId,
            method: $request?->getMethod(),
            route: is_object($route) ? ($route->getName() ?? $route->uri()) : null,
            path: $request !== null ? '/'.ltrim($request->path(), '/') : null,
            userId: $this->userId(),
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC'))->format(DateTimeInterface::RFC3339_EXTENDED),
            chain: $this->chain($original),
            context: $this->sanitizer->sanitize($exception->context),
        );
    }

    private function safeMessage(Throwable $exception): string
    {
        // QueryException messages embed the SQL with bound values; use the driver message instead.
        $message = $exception instanceof QueryException
            ? (string) ($exception->getPrevious()?->getMessage() ?? 'Database query failed')
            : $exception->getMessage();

        $message = $this->sanitizer->scrubString($message);

        return mb_strlen($message) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($message, 0, self::MAX_MESSAGE_LENGTH).'…'
            : $message;
    }

    /**
     * @return list<string>
     */
    private function chain(Throwable $exception): array
    {
        $chain = [];
        $current = $exception->getPrevious();

        while ($current !== null && count($chain) < 10) {
            $chain[] = $current::class;
            $current = $current->getPrevious();
        }

        return $chain;
    }

    private function currentRequest(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        return $request instanceof Request && $request->server('REQUEST_METHOD') !== null ? $request : null;
    }

    private function userId(): string|int|null
    {
        try {
            if (! $this->container->bound('auth')) {
                return null;
            }

            $guard = $this->container->make('auth')->guard();

            // hasUser() does not trigger authentication (no extra DB queries in an error path).
            if (method_exists($guard, 'hasUser') && $guard->hasUser()) {
                $id = $guard->id();

                return is_int($id) || is_string($id) ? $id : null;
            }
        } catch (Throwable) {
            // Identity is best effort.
        }

        return null;
    }
}
