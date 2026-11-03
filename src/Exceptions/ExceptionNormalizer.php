<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Http\Client\ConnectionException as HttpClientConnectionException;
use Illuminate\Http\Client\RequestException as HttpClientRequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Exceptions\BackedEnumCaseNotFoundException;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use PDOException;
use ReflectionClass;
use RoayaPay\PlatformCore\Codes\InvalidApiCodeRegistryException;
use RoayaPay\PlatformCore\Codes\UnknownApiCodeException;
use RoayaPay\PlatformCore\Exceptions\Attributes\HandlesExceptions;
use RoayaPay\PlatformCore\Exceptions\Contracts\ExceptionMapper;
use RoayaPay\PlatformCore\Response\RoayaPayJsonResponse;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Converts any Throwable into a RoayaPayException, centrally.
 *
 * Resolution order:
 *  1. RoayaPayException → returned unchanged.
 *  2. Laravel's "prepared" HTTP wrappers (e.g. NotFoundHttpException wrapping
 *     ModelNotFoundException) are unwrapped so the real cause is classified.
 *  3. Application mappings (config map + #[HandlesExceptions] mappers), most specific class first.
 *  4. Built-in framework / HTTP-client / database rules.
 *  5. A RoayaPayException found in the previous-chain (e.g. wrapped by a view).
 *  6. Anything else → INTERNAL (CODE_ERR, HTTP 500), reported.
 *
 * The original exception is always kept as $previous. Its message, SQL,
 * bindings, trace and file paths never become client-visible: they are only
 * placed in internal context where needed, and the monitor sanitizes them.
 *
 * Mappers are registered at boot time. The class → mapper index is built once
 * (one reflection read per mapper class), so normalization does no reflection.
 */
final class ExceptionNormalizer
{
    /** @var array<class-string, list<ExceptionMapper>> */
    private array $mappers = [];

    /** @var array<class-string, array{code: string, category: ErrorCategory}> */
    private array $map = [];

    /**
     * @param  array<class-string, string|array{code: string, category?: string|ErrorCategory}>  $map
     * @param  iterable<ExceptionMapper>  $mappers
     * @param  array<class-string, string>  $modelNotFound  Model class => API code.
     */
    public function __construct(array $map = [], iterable $mappers = [], private readonly array $modelNotFound = [])
    {
        foreach ($map as $exception => $target) {
            $this->addMapping($exception, $target);
        }

        foreach ($mappers as $mapper) {
            $this->registerMapper($mapper);
        }
    }

    /**
     * Map an exception class (or interface) to a registered API code.
     *
     * @param  class-string  $exception
     * @param  string|array{code: string, category?: string|ErrorCategory}  $target
     */
    public function addMapping(string $exception, string|array $target): void
    {
        $code = is_string($target) ? $target : ($target['code'] ?? throw new InvalidArgumentException("Mapping for [$exception] needs a 'code'."));
        $category = is_array($target) && isset($target['category'])
            ? ($target['category'] instanceof ErrorCategory ? $target['category'] : ErrorCategory::from(strtoupper($target['category'])))
            : ErrorCategory::BUSINESS;

        $this->map[$exception] = ['code' => $code, 'category' => $category];
    }

    /**
     * Register a mapper annotated with #[HandlesExceptions(...)].
     *
     * @throws LogicException when the attribute is missing or empty.
     */
    public function registerMapper(ExceptionMapper $mapper): void
    {
        $attributes = new ReflectionClass($mapper)->getAttributes(HandlesExceptions::class);

        if ($attributes === []) {
            throw new LogicException(sprintf('Exception mapper [%s] must declare #[HandlesExceptions(...)].', $mapper::class));
        }

        $handled = $attributes[0]->newInstance()->exceptions;

        if ($handled === []) {
            throw new LogicException(sprintf('Exception mapper [%s] declares no exception classes.', $mapper::class));
        }

        foreach ($handled as $class) {
            $this->mappers[$class][] = $mapper;
        }
    }

    /**
     * Number of exception classes with registered mappers (diagnostics/tests).
     */
    public function mappedClassCount(): int
    {
        return count($this->mappers) + count($this->map);
    }

    public function normalize(Throwable $exception): RoayaPayException
    {
        if ($exception instanceof RoayaPayException) {
            return $exception;
        }

        $cause = $this->unwrapPrepared($exception);

        return $this->mapApplication($cause)
            ?? $this->mapBuiltIn($cause)
            ?? $this->findInChain($cause)
            ?? RoayaPayException::internal(context: ['exception' => $cause::class], previous: $cause);
    }

    /**
     * Laravel wraps some exceptions in HTTP exceptions before render callbacks run
     * (Handler::prepareException). Classify the real cause instead.
     */
    private function unwrapPrepared(Throwable $exception): Throwable
    {
        $previous = $exception->getPrevious();

        if (! $exception instanceof HttpExceptionInterface || $previous === null) {
            return $exception;
        }

        $preparedCauses = [
            ModelNotFoundException::class, AuthorizationException::class, TokenMismatchException::class,
            RecordsNotFoundException::class, RecordNotFoundException::class, BackedEnumCaseNotFoundException::class,
            RequestExceptionInterface::class, RoayaPayException::class,
        ];

        return array_any($preparedCauses, fn (string $class): bool => $previous instanceof $class) ? $previous : $exception;
    }

    private function mapApplication(Throwable $exception): ?RoayaPayException
    {
        if ($this->mappers === [] && $this->map === []) {
            return null;
        }

        // Most specific first: the class itself, then its parents, then interfaces.
        $types = [$exception::class, ...array_values(class_parents($exception) ?: []), ...array_values(class_implements($exception) ?: [])];

        foreach ($types as $type) {
            foreach ($this->mappers[$type] ?? [] as $mapper) {
                $mapped = $mapper->map($exception);

                if ($mapped !== null) {
                    return $mapped->getPrevious() === null && $mapped !== $exception
                        ? $this->withPrevious($mapped, $exception)
                        : $mapped;
                }
            }

            if (isset($this->map[$type])) {
                $target = $this->map[$type];

                return new RoayaPayException(
                    $target['code'],
                    $target['category'],
                    context: ['exception' => $exception::class],
                    previous: $exception,
                );
            }
        }

        return null;
    }

    private function mapBuiltIn(Throwable $e): ?RoayaPayException
    {
        return match (true) {
            $e instanceof ValidationException => RoayaPayException::validation($e),

            $e instanceof AuthenticationException => RoayaPayException::unauthorized(
                context: ['guards' => $e->guards()],
                previous: $e,
            ),

            $e instanceof AuthorizationException => RoayaPayException::forbidden(previous: $e),

            $e instanceof ModelNotFoundException => RoayaPayException::notFound(
                $this->modelNotFound[$e->getModel()] ?? 'NOT_FOUND',
                context: ['model' => $e->getModel(), 'id_count' => count($e->getIds())],
                previous: $e,
            ),

            $e instanceof RecordsNotFoundException,
            $e instanceof RecordNotFoundException,
            $e instanceof BackedEnumCaseNotFoundException => RoayaPayException::notFound(previous: $e),

            $e instanceof TokenMismatchException,
            $e instanceof InvalidSignatureException,
            $e instanceof SuspiciousOperationException,
            $e instanceof DecryptException => RoayaPayException::security(
                context: ['exception' => $e::class],
                previous: $e,
            ),

            $e instanceof ThrottleRequestsException => RoayaPayException::security('TOO_MANY_REQUESTS', previous: $e)
                ->withSeverity(Severity::INFO),

            $e instanceof PostTooLargeException => new RoayaPayException('REQUEST_TOO_LARGE', ErrorCategory::VALIDATION, previous: $e),

            $e instanceof RequestExceptionInterface => new RoayaPayException('BAD_REQUEST', ErrorCategory::VALIDATION, previous: $e),

            $e instanceof HttpResponseException => $this->fromHttpResponseException($e),

            $e instanceof HttpExceptionInterface => $this->fromHttpStatus($e),

            $e instanceof DeadlockException,
            $e instanceof LostConnectionException => RoayaPayException::database(
                context: ['exception' => $e::class],
                previous: $e,
                retryable: true,
            ),

            $e instanceof QueryException => $this->fromQueryException($e),

            $e instanceof PDOException => RoayaPayException::database(
                context: ['exception' => $e::class, 'sqlstate' => (string) $e->getCode()],
                previous: $e,
            ),

            $e instanceof HttpClientConnectionException => $this->fromTransportFailure($e, $e->getMessage(), null),

            $e instanceof HttpClientRequestException => $this->fromProviderStatus($e, $e->response->status()),

            $this->isA($e, 'GuzzleHttp\Exception\ConnectException') => $this->fromTransportFailure(
                $e,
                $e->getMessage(),
                $this->guzzleErrno($e),
            ),

            $this->isA($e, 'GuzzleHttp\Exception\BadResponseException') => $this->fromProviderStatus(
                $e,
                (int) $e->getResponse()->getStatusCode(), // @phpstan-ignore method.notFound
            ),

            $this->isA($e, 'Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface') => RoayaPayException::external(
                'EXTERNAL_SERVICE_TIMEOUT',
                ['exception' => $e::class],
                $e,
                retryable: false,
            ),

            $this->isA($e, 'Psr\Http\Client\NetworkExceptionInterface') => RoayaPayException::external(
                'EXTERNAL_SERVICE_UNAVAILABLE',
                ['exception' => $e::class],
                $e,
                retryable: true,
            ),

            $this->isA($e, 'Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface'),
            $this->isA($e, 'Psr\Http\Client\ClientExceptionInterface'),
            $this->isA($e, 'GuzzleHttp\Exception\TransferException') => RoayaPayException::external(
                'EXTERNAL_SERVICE_ERROR',
                ['exception' => $e::class],
                $e,
                retryable: false,
            ),

            $e instanceof BindingResolutionException,
            $e instanceof MissingAppKeyException,
            $e instanceof UnknownApiCodeException,
            $e instanceof InvalidApiCodeRegistryException => RoayaPayException::system(
                context: ['exception' => $e::class],
                previous: $e,
            ),

            $e instanceof \Error => RoayaPayException::internal(context: ['exception' => $e::class], previous: $e)
                ->withSeverity(Severity::CRITICAL),

            default => null,
        };
    }

    private function fromHttpStatus(HttpExceptionInterface&Throwable $e): RoayaPayException
    {
        $status = $e->getStatusCode();

        return match (true) {
            $status === 401 => RoayaPayException::unauthorized(previous: $e),
            $status === 403 => RoayaPayException::forbidden(previous: $e),
            $status === 404 => RoayaPayException::notFound('ROUTE_NOT_FOUND', previous: $e),
            $status === 405 => RoayaPayException::notFound('METHOD_NOT_ALLOWED', previous: $e),
            $status === 413 => new RoayaPayException('REQUEST_TOO_LARGE', ErrorCategory::VALIDATION, previous: $e),
            $status === 419 => RoayaPayException::security(previous: $e),
            $status === 429 => RoayaPayException::security('TOO_MANY_REQUESTS', previous: $e)->withSeverity(Severity::INFO),
            $status === 503 => RoayaPayException::system('SERVICE_UNAVAILABLE', ['http_status' => $status], $e)
                ->withSeverity(Severity::WARNING),
            $status >= 400 && $status < 500 => new RoayaPayException('BAD_REQUEST', ErrorCategory::VALIDATION, context: ['http_status' => $status], previous: $e),
            default => RoayaPayException::internal(context: ['http_status' => $status], previous: $e),
        };
    }

    private function fromHttpResponseException(HttpResponseException $e): RoayaPayException
    {
        // A RoayaPay response thrown via HttpResponseException is rendered as-is by the renderer.
        return RoayaPayException::internal(
            'RESPONSE_CONTRACT_VIOLATION',
            context: [
                'exception' => $e::class,
                'response' => $e->getResponse()::class,
                'approved' => $e->getResponse() instanceof RoayaPayJsonResponse,
            ],
            previous: $e,
        );
    }

    private function fromQueryException(QueryException $e): RoayaPayException
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverMessage = strtolower((string) $e->getPrevious()?->getMessage());

        // Serialization failures / deadlocks are transient; the whole DB transaction can be retried.
        $retryable = in_array($sqlState, ['40001', '40P01'], true)
            || str_contains($driverMessage, 'deadlock')
            || str_contains($driverMessage, 'lock wait timeout');

        return RoayaPayException::database(
            context: [
                'exception' => $e::class,
                'sqlstate' => $sqlState,
                'connection' => $e->getConnectionName(),
                // SQL template only – bindings (which may contain personal or payment data) are never logged.
                'sql' => $e->getSql(),
            ],
            previous: $e,
            retryable: $retryable,
        );
    }

    /**
     * A transport failure: either the request never reached the provider
     * (safe to retry) or it may have been processed (outcome unknown).
     */
    private function fromTransportFailure(Throwable $e, string $message, ?int $errno): RoayaPayException
    {
        if ($errno === null && preg_match('/cURL error (\d+)/', $message, $m)) {
            $errno = (int) $m[1];
        }

        $timedOut = $errno === 28 || str_contains(strtolower($message), 'timed out');

        // cURL 6/7/35: host not resolved, connection refused, TLS handshake failed → nothing was sent.
        $notDelivered = in_array($errno, [5, 6, 7, 35, 60], true);

        return match (true) {
            $timedOut => RoayaPayException::external('EXTERNAL_SERVICE_TIMEOUT', ['exception' => $e::class, 'errno' => $errno], $e, retryable: false),
            $notDelivered => RoayaPayException::external('EXTERNAL_SERVICE_UNAVAILABLE', ['exception' => $e::class, 'errno' => $errno], $e, retryable: true),
            default => RoayaPayException::external('EXTERNAL_SERVICE_ERROR', ['exception' => $e::class, 'errno' => $errno], $e, retryable: false),
        };
    }

    /**
     * The provider answered: 4xx = it explicitly rejected the call (nothing happened),
     * 5xx = provider failure (outcome unknown).
     */
    private function fromProviderStatus(Throwable $e, int $status): RoayaPayException
    {
        $context = ['exception' => $e::class, 'provider_status' => $status];

        return match (true) {
            $status === 408 || $status === 504 => RoayaPayException::external('EXTERNAL_SERVICE_TIMEOUT', $context, $e, retryable: false),
            $status === 429 || $status === 503 => RoayaPayException::external('EXTERNAL_SERVICE_UNAVAILABLE', $context, $e, retryable: true),
            $status >= 400 && $status < 500 => RoayaPayException::external('EXTERNAL_SERVICE_REJECTED', $context, $e, retryable: false),
            default => RoayaPayException::external('EXTERNAL_SERVICE_ERROR', $context, $e, retryable: false),
        };
    }

    private function findInChain(Throwable $exception): ?RoayaPayException
    {
        $depth = 0;
        $current = $exception->getPrevious();

        while ($current !== null && $depth++ < 10) {
            if ($current instanceof RoayaPayException) {
                return $current;
            }
            $current = $current->getPrevious();
        }

        return null;
    }

    private function withPrevious(RoayaPayException $mapped, Throwable $original): RoayaPayException
    {
        // Exceptions cannot be cloned; rebuild with the original as previous so the chain is preserved.
        $rebuilt = new RoayaPayException(
            $mapped->apiCode,
            $mapped->category,
            $mapped->data,
            $mapped->messageOverride?->messages,
            $mapped->context,
            $mapped->severity,
            $mapped->reporting,
            $mapped->retryable,
            $original,
        );

        if ($mapped->transactionId !== null) {
            $rebuilt->withTransactionId($mapped->transactionId);
        }

        return $rebuilt;
    }

    private function isA(Throwable $e, string $class): bool
    {
        return (class_exists($class, false) || interface_exists($class, false)) && $e instanceof $class;
    }

    private function guzzleErrno(Throwable $e): ?int
    {
        if (! method_exists($e, 'getHandlerContext')) {
            return null;
        }

        $errno = $e->getHandlerContext()['errno'] ?? null;

        return is_int($errno) ? $errno : null;
    }
}
