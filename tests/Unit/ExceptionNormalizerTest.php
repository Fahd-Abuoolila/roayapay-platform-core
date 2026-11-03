<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RoayaPay\PlatformCore\Codes\UnknownApiCodeException;
use RoayaPay\PlatformCore\Exceptions\ErrorCategory;
use RoayaPay\PlatformCore\Exceptions\ExceptionNormalizer;
use RoayaPay\PlatformCore\Exceptions\RoayaPayException;
use RoayaPay\PlatformCore\Tests\Fixtures\AcmeDeclinedException;
use RoayaPay\PlatformCore\Tests\Fixtures\AcmeException;
use RoayaPay\PlatformCore\Tests\Fixtures\AcmeGatewayMapper;
use RoayaPay\PlatformCore\Tests\Fixtures\AcmeTimeoutException;
use RoayaPay\PlatformCore\Tests\Fixtures\AcmeUnknownException;
use RoayaPay\PlatformCore\Tests\Fixtures\FakeMerchant;
use RoayaPay\PlatformCore\Tests\Fixtures\MapperWithoutAttribute;
use RoayaPay\PlatformCore\Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ExceptionNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{\Closure(): Throwable, string, ErrorCategory}>
     */
    public static function exceptions(): array
    {
        return [
            'validation' => [fn () => ValidationException::withMessages(['email' => 'x']), 'VALIDATION_ERROR', ErrorCategory::VALIDATION],
            'authentication' => [fn () => new AuthenticationException(), 'UNAUTHORIZED', ErrorCategory::AUTHENTICATION],
            'authorization' => [fn () => new AuthorizationException(), 'FORBIDDEN', ErrorCategory::AUTHORIZATION],
            'model not found' => [fn () => (new ModelNotFoundException())->setModel(FakeMerchant::class, [1]), 'NOT_FOUND', ErrorCategory::RESOURCE],
            'prepared model not found' => [fn () => new NotFoundHttpException('x', (new ModelNotFoundException())->setModel(FakeMerchant::class)), 'NOT_FOUND', ErrorCategory::RESOURCE],
            'prepared authorization' => [fn () => new AccessDeniedHttpException('x', new AuthorizationException()), 'FORBIDDEN', ErrorCategory::AUTHORIZATION],
            'route not found' => [fn () => new NotFoundHttpException(), 'ROUTE_NOT_FOUND', ErrorCategory::RESOURCE],
            'method not allowed' => [fn () => new MethodNotAllowedHttpException(['POST']), 'METHOD_NOT_ALLOWED', ErrorCategory::RESOURCE],
            'http 401' => [fn () => new HttpException(401), 'UNAUTHORIZED', ErrorCategory::AUTHENTICATION],
            'http 403' => [fn () => new HttpException(403), 'FORBIDDEN', ErrorCategory::AUTHORIZATION],
            'http 418' => [fn () => new HttpException(418), 'BAD_REQUEST', ErrorCategory::VALIDATION],
            'http 503' => [fn () => new HttpException(503), 'SERVICE_UNAVAILABLE', ErrorCategory::SYSTEM],
            'http 500' => [fn () => new HttpException(500), 'CODE_ERR', ErrorCategory::INTERNAL],
            'throttle' => [fn () => new ThrottleRequestsException(), 'TOO_MANY_REQUESTS', ErrorCategory::SECURITY],
            'csrf' => [fn () => new TokenMismatchException(), 'SECURITY_VIOLATION', ErrorCategory::SECURITY],
            'signature' => [fn () => new InvalidSignatureException(), 'SECURITY_VIOLATION', ErrorCategory::SECURITY],
            'query' => [fn () => new QueryException('testing', 'select 1', [], new PDOException('boom')), 'CODE_ERR', ErrorCategory::DATABASE],
            'unique violation' => [fn () => new UniqueConstraintViolationException('testing', 'insert', [], new PDOException('dup')), 'CODE_ERR', ErrorCategory::DATABASE],
            'pdo' => [fn () => new PDOException('gone'), 'CODE_ERR', ErrorCategory::DATABASE],
            'http client timeout' => [fn () => new ConnectionException('cURL error 28: Operation timed out'), 'EXTERNAL_SERVICE_TIMEOUT', ErrorCategory::EXTERNAL_SERVICE],
            'http client refused' => [fn () => new ConnectionException('cURL error 7: Failed to connect'), 'EXTERNAL_SERVICE_UNAVAILABLE', ErrorCategory::EXTERNAL_SERVICE],
            'http client other' => [fn () => new ConnectionException('cURL error 56: Recv failure'), 'EXTERNAL_SERVICE_ERROR', ErrorCategory::EXTERNAL_SERVICE],
            'provider 422' => [fn () => new RequestException(new ClientResponse(new PsrResponse(422))), 'EXTERNAL_SERVICE_REJECTED', ErrorCategory::EXTERNAL_SERVICE],
            'provider 502' => [fn () => new RequestException(new ClientResponse(new PsrResponse(502))), 'EXTERNAL_SERVICE_ERROR', ErrorCategory::EXTERNAL_SERVICE],
            'provider 504' => [fn () => new RequestException(new ClientResponse(new PsrResponse(504))), 'EXTERNAL_SERVICE_TIMEOUT', ErrorCategory::EXTERNAL_SERVICE],
            'guzzle connect timeout' => [fn () => new ConnectException('timeout', new PsrRequest('POST', 'https://psp.test'), null, ['errno' => 28]), 'EXTERNAL_SERVICE_TIMEOUT', ErrorCategory::EXTERNAL_SERVICE],
            'guzzle connect refused' => [fn () => new ConnectException('refused', new PsrRequest('POST', 'https://psp.test'), null, ['errno' => 7]), 'EXTERNAL_SERVICE_UNAVAILABLE', ErrorCategory::EXTERNAL_SERVICE],
            'guzzle server error' => [fn () => new ServerException('500', new PsrRequest('POST', 'https://psp.test'), new PsrResponse(500)), 'EXTERNAL_SERVICE_ERROR', ErrorCategory::EXTERNAL_SERVICE],
            'unknown api code' => [fn () => UnknownApiCodeException::forKey('X'), 'CODE_ERR', ErrorCategory::SYSTEM],
            'php error' => [fn () => new \TypeError('bad type'), 'CODE_ERR', ErrorCategory::INTERNAL],
            'unexpected' => [fn () => new RuntimeException('surprise'), 'CODE_ERR', ErrorCategory::INTERNAL],
            'logic' => [fn () => new LogicException('bug'), 'CODE_ERR', ErrorCategory::INTERNAL],
        ];
    }

    /**
     * @param  \Closure(): Throwable  $make
     */
    #[Test]
    #[DataProvider('exceptions')]
    public function framework_and_third_party_exceptions_are_normalized(\Closure $make, string $code, ErrorCategory $category): void
    {
        $original = $make();
        $normalized = $this->app->make(ExceptionNormalizer::class)->normalize($original);

        $this->assertSame(RoayaPayException::class, $normalized::class);
        $this->assertSame($code, $normalized->apiCode);
        $this->assertSame($category, $normalized->category);
        $this->assertNotNull($normalized->getPrevious(), 'The original exception must be preserved');
    }

    #[Test]
    public function the_real_cause_is_kept_as_previous_for_prepared_exceptions(): void
    {
        $cause = (new ModelNotFoundException())->setModel(FakeMerchant::class, [3]);
        $normalized = $this->app->make(ExceptionNormalizer::class)->normalize(new NotFoundHttpException('x', $cause));

        $this->assertSame($cause, $normalized->getPrevious());
        $this->assertSame(FakeMerchant::class, $normalized->context['model']);
    }

    #[Test]
    public function timeouts_are_never_retryable_but_undelivered_requests_are(): void
    {
        $normalizer = $this->app->make(ExceptionNormalizer::class);

        $this->assertFalse($normalizer->normalize(new ConnectionException('cURL error 28: timed out'))->retryable);
        $this->assertTrue($normalizer->normalize(new ConnectionException('cURL error 6: Could not resolve host'))->retryable);
    }

    #[Test]
    public function query_exceptions_keep_bindings_out_of_the_context(): void
    {
        $exception = new QueryException('testing', 'select * from cards where pan = ?', ['4111111111111111'], new PDOException('x'));
        $normalized = $this->app->make(ExceptionNormalizer::class)->normalize($exception);

        $this->assertSame('select * from cards where pan = ?', $normalized->context['sql']);
        $this->assertStringNotContainsString('4111111111111111', (string) json_encode($normalized->context));
    }

    #[Test]
    public function model_not_found_can_map_to_a_specific_code(): void
    {
        config(['roayapay.exceptions.model_not_found' => [FakeMerchant::class => 'MERCHANT_NOT_FOUND']]);
        $this->app->forgetInstance(ExceptionNormalizer::class);

        $normalized = RoayaPayException::fromThrowable((new ModelNotFoundException())->setModel(FakeMerchant::class, [1]));

        $this->assertSame('MERCHANT_NOT_FOUND', $normalized->apiCode);
    }

    #[Test]
    public function config_mappings_apply_to_subclasses(): void
    {
        $normalizer = new ExceptionNormalizer(map: [AcmeException::class => ['code' => 'PAYMENT_DECLINED', 'category' => 'payment']]);

        $normalized = $normalizer->normalize(new AcmeUnknownException('x'));

        $this->assertSame('PAYMENT_DECLINED', $normalized->apiCode);
        $this->assertSame(ErrorCategory::PAYMENT, $normalized->category);
        $this->assertInstanceOf(AcmeUnknownException::class, $normalized->getPrevious());
    }

    #[Test]
    public function attribute_mappers_are_indexed_and_keep_the_chain(): void
    {
        $normalizer = new ExceptionNormalizer(mappers: [new AcmeGatewayMapper()]);

        $declined = new AcmeDeclinedException('insufficient_funds');
        $mapped = $normalizer->normalize($declined);

        $this->assertSame('PAYMENT_DECLINED', $mapped->apiCode);
        $this->assertSame(ErrorCategory::PAYMENT, $mapped->category);
        $this->assertSame($declined, $mapped->getPrevious());
        $this->assertSame('insufficient_funds', $mapped->context['reason']);

        $timeout = $normalizer->normalize(new AcmeTimeoutException());
        $this->assertSame('PAYMENT_STATUS_UNKNOWN', $timeout->apiCode);
        $this->assertFalse($timeout->retryable);

        // Not declared in the attribute → mapper is never consulted → built-in fallback.
        $this->assertSame('CODE_ERR', $normalizer->normalize(new AcmeUnknownException())->apiCode);
    }

    #[Test]
    public function mappers_from_config_are_registered(): void
    {
        config(['roayapay.exceptions.mappers' => [AcmeGatewayMapper::class]]);
        $this->app->forgetInstance(ExceptionNormalizer::class);

        $this->assertSame('PAYMENT_DECLINED', RoayaPayException::fromThrowable(new AcmeDeclinedException())->apiCode);
    }

    #[Test]
    public function a_mapper_without_the_attribute_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('#[HandlesExceptions');

        new ExceptionNormalizer(mappers: [new MapperWithoutAttribute()]);
    }

    #[Test]
    public function a_roayapay_exception_wrapped_by_another_exception_is_found(): void
    {
        $inner = RoayaPayException::business('INSUFFICIENT_BALANCE');
        $wrapped = new RuntimeException('view failed', 0, $inner);

        $this->assertSame($inner, $this->app->make(ExceptionNormalizer::class)->normalize($wrapped));
    }

    #[Test]
    public function business_rejections_are_never_turned_into_internal_errors(): void
    {
        $normalizer = $this->app->make(ExceptionNormalizer::class);

        foreach (['INSUFFICIENT_BALANCE', 'MERCHANT_NOT_FOUND', 'PAYMENT_DECLINED'] as $code) {
            $this->assertSame($code, $normalizer->normalize(RoayaPayException::business($code))->apiCode);
        }
    }

    #[Test]
    public function normalization_does_no_per_call_reflection(): void
    {
        $normalizer = new ExceptionNormalizer(mappers: [new AcmeGatewayMapper()]);
        $exception = new AcmeDeclinedException();

        $start = hrtime(true);
        for ($i = 0; $i < 5000; $i++) {
            $normalizer->normalize($exception);
        }
        $ms = (hrtime(true) - $start) / 1e6;

        // Indexed lookup: 5,000 normalizations stay far below what per-call reflection would cost.
        $this->assertLessThan(1500, $ms, "5000 normalizations took {$ms}ms");
    }
}
