<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Unit;

use Illuminate\Support\MessageBag;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use RoayaPay\PlatformCore\Codes\ApiCodeRegistry;
use RoayaPay\PlatformCore\Exceptions\ErrorCategory;
use RoayaPay\PlatformCore\Exceptions\ReportingPolicy;
use RoayaPay\PlatformCore\Exceptions\RoayaPayException;
use RoayaPay\PlatformCore\Exceptions\Severity;
use RoayaPay\PlatformCore\Tests\TestCase;
use RuntimeException;

final class RoayaPayExceptionTest extends TestCase
{
    /**
     * @return array<string, array{\Closure(): RoayaPayException, string, ErrorCategory, ReportingPolicy}>
     */
    public static function factories(): array
    {
        return [
            'business' => [fn () => RoayaPayException::business('INSUFFICIENT_BALANCE'), 'INSUFFICIENT_BALANCE', ErrorCategory::BUSINESS, ReportingPolicy::DONT_REPORT],
            'business default' => [fn () => RoayaPayException::business(), 'BUSINESS_RULE_VIOLATION', ErrorCategory::BUSINESS, ReportingPolicy::DONT_REPORT],
            'payment' => [fn () => RoayaPayException::payment(), 'PAYMENT_DECLINED', ErrorCategory::PAYMENT, ReportingPolicy::REPORT],
            'validation' => [fn () => RoayaPayException::validation(['email' => 'Email is required']), 'VALIDATION_ERROR', ErrorCategory::VALIDATION, ReportingPolicy::DONT_REPORT],
            'unauthorized' => [fn () => RoayaPayException::unauthorized(), 'UNAUTHORIZED', ErrorCategory::AUTHENTICATION, ReportingPolicy::DONT_REPORT],
            'forbidden' => [fn () => RoayaPayException::forbidden(), 'FORBIDDEN', ErrorCategory::AUTHORIZATION, ReportingPolicy::SECURITY],
            'notFound' => [fn () => RoayaPayException::notFound('MERCHANT_NOT_FOUND'), 'MERCHANT_NOT_FOUND', ErrorCategory::RESOURCE, ReportingPolicy::DONT_REPORT],
            'notFound default' => [fn () => RoayaPayException::notFound(), 'NOT_FOUND', ErrorCategory::RESOURCE, ReportingPolicy::DONT_REPORT],
            'external' => [fn () => RoayaPayException::external('PAYMENT_PROVIDER_UNAVAILABLE'), 'PAYMENT_PROVIDER_UNAVAILABLE', ErrorCategory::EXTERNAL_SERVICE, ReportingPolicy::REPORT],
            'database' => [fn () => RoayaPayException::database(), 'CODE_ERR', ErrorCategory::DATABASE, ReportingPolicy::REPORT],
            'security' => [fn () => RoayaPayException::security(), 'SECURITY_VIOLATION', ErrorCategory::SECURITY, ReportingPolicy::SECURITY],
            'internal' => [fn () => RoayaPayException::internal('PAYMENT_PROCESSING_FAILED'), 'PAYMENT_PROCESSING_FAILED', ErrorCategory::INTERNAL, ReportingPolicy::REPORT],
            'system' => [fn () => RoayaPayException::system(), 'CODE_ERR', ErrorCategory::SYSTEM, ReportingPolicy::REPORT],
            'fromThrowable' => [fn () => RoayaPayException::fromThrowable(new RuntimeException('x')), 'CODE_ERR', ErrorCategory::INTERNAL, ReportingPolicy::REPORT],
            'constructor' => [fn () => new RoayaPayException('INSUFFICIENT_BALANCE'), 'INSUFFICIENT_BALANCE', ErrorCategory::BUSINESS, ReportingPolicy::DONT_REPORT],
        ];
    }

    /**
     * @param  \Closure(): RoayaPayException  $factory
     */
    #[Test]
    #[DataProvider('factories')]
    public function every_factory_returns_exactly_roayapay_exception_with_registered_code(\Closure $factory, string $code, ErrorCategory $category, ReportingPolicy $policy): void
    {
        $exception = $factory();

        $this->assertSame(RoayaPayException::class, $exception::class);
        $this->assertSame($code, $exception->apiCode);
        $this->assertSame($category, $exception->category);
        $this->assertSame($policy, $exception->reporting);
        $this->assertTrue($this->app->make(ApiCodeRegistry::class)->has($exception->apiCode), "Default code [$code] must be registered");
    }

    #[Test]
    public function the_class_is_final_and_every_public_static_method_returns_self(): void
    {
        $class = new ReflectionClass(RoayaPayException::class);
        $this->assertTrue($class->isFinal(), 'There must be no category subclasses.');
        $this->assertTrue($class->isSubclassOf(RuntimeException::class));

        $statics = array_filter($class->getMethods(ReflectionMethod::IS_PUBLIC), fn (ReflectionMethod $m): bool => $m->isStatic());
        $this->assertGreaterThanOrEqual(11, count($statics));

        foreach ($statics as $method) {
            $this->assertSame('self', (string) $method->getReturnType(), $method->getName().' must return self');
        }
    }

    #[Test]
    public function every_category_has_a_registered_default_code(): void
    {
        $registry = $this->app->make(ApiCodeRegistry::class);

        foreach (ErrorCategory::cases() as $category) {
            $this->assertTrue($registry->has($category->defaultCode()), $category->name);
        }
    }

    #[Test]
    public function category_does_not_decide_the_http_status(): void
    {
        $registry = $this->app->make(ApiCodeRegistry::class);

        // Same category, different HTTP status: the registered code decides.
        $this->assertSame(500, $registry->get(RoayaPayException::external('EXTERNAL_SERVICE_TIMEOUT')->apiCode)->http);
        $this->assertSame(200, $registry->get(RoayaPayException::external('PAYMENT_DECLINED')->apiCode)->http);
    }

    #[Test]
    public function the_previous_exception_is_preserved(): void
    {
        $original = new RuntimeException('root cause');

        $this->assertSame($original, RoayaPayException::internal(previous: $original)->getPrevious());
        $this->assertSame($original, RoayaPayException::fromThrowable($original)->getPrevious());
        $this->assertSame($original, RoayaPayException::business('INSUFFICIENT_BALANCE', previous: $original)->getPrevious());
    }

    #[Test]
    public function from_throwable_returns_an_existing_roayapay_exception_unchanged(): void
    {
        $exception = RoayaPayException::business('INSUFFICIENT_BALANCE');

        $this->assertSame($exception, RoayaPayException::fromThrowable($exception));
    }

    #[Test]
    public function the_php_message_is_internal_and_safe(): void
    {
        $exception = RoayaPayException::business('INSUFFICIENT_BALANCE', ['balance' => 5], context: ['password' => 'x']);

        $this->assertSame('RoayaPay BUSINESS exception [INSUFFICIENT_BALANCE]', $exception->getMessage());
    }

    #[Test]
    public function bilingual_overrides_are_validated(): void
    {
        $exception = RoayaPayException::business('INSUFFICIENT_BALANCE', message: ['ar' => 'أ', 'en' => 'A']);
        $this->assertSame('A', $exception->messageOverride?->in('en'));

        $this->expectException(InvalidArgumentException::class);
        RoayaPayException::business('INSUFFICIENT_BALANCE', message: ['en' => 'only english']);
    }

    #[Test]
    public function fluent_modifiers_update_metadata(): void
    {
        $exception = RoayaPayException::business('INSUFFICIENT_BALANCE')
            ->withData(['available' => '1.00'])
            ->withContext(['merchant_id' => 1])
            ->withContext(['wallet' => 2])
            ->withSeverity(Severity::WARNING)
            ->withReporting(ReportingPolicy::REPORT)
            ->retryable()
            ->withTransactionId('txn_12345');

        $this->assertSame(['available' => '1.00'], $exception->data);
        $this->assertSame(['merchant_id' => 1, 'wallet' => 2], $exception->context);
        $this->assertSame(Severity::WARNING, $exception->severity);
        $this->assertTrue($exception->isReportable());
        $this->assertTrue($exception->retryable);
        $this->assertSame('txn_12345', $exception->transactionId);
    }

    #[Test]
    public function correlation_is_filled_but_never_overwritten(): void
    {
        $exception = RoayaPayException::business()->withTransactionId('txn_original');
        $exception->withCorrelation('req-123456789', 'txn_other');

        $this->assertSame('req-123456789', $exception->requestId);
        $this->assertSame('txn_original', $exception->transactionId);
    }

    #[Test]
    public function validation_accepts_every_error_representation(): void
    {
        $validator = \Illuminate\Support\Facades\Validator::make([], ['email' => 'required']);
        $validator->fails();

        foreach ([
            new MessageBag(['email' => ['Email is required']]),
            $validator,
            new \Illuminate\Validation\ValidationException($validator),
            ['email' => 'Email is required'],
            ['email' => ['Email is required', 'second']],
        ] as $errors) {
            $exception = RoayaPayException::validation($errors);

            $this->assertTrue($exception->hasValidationErrors());
            $this->assertSame('VALIDATION_ERROR', $exception->apiCode);
        }
    }

    #[Test]
    public function properties_cannot_be_written_from_outside(): void
    {
        $exception = RoayaPayException::business();

        $this->expectException(\Error::class);
        $exception->apiCode = 'SUCCESS'; // @phpstan-ignore-line
    }

    #[Test]
    public function the_exception_can_be_created_without_a_laravel_container(): void
    {
        $container = \Illuminate\Container\Container::getInstance();
        \Illuminate\Container\Container::setInstance(new \Illuminate\Container\Container());

        try {
            $exception = RoayaPayException::fromThrowable(new \Illuminate\Auth\AuthenticationException());
            $this->assertSame('UNAUTHORIZED', $exception->apiCode);
        } finally {
            \Illuminate\Container\Container::setInstance($container);
        }
    }
}
