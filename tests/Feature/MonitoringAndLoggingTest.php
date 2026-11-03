<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Feature;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RoayaPay\PlatformCore\Exceptions\RoayaPayException;
use RoayaPay\PlatformCore\Logging\RoayaPayLogger;
use RoayaPay\PlatformCore\Monitoring\Contracts\ErrorReporter;
use RoayaPay\PlatformCore\Monitoring\ErrorMonitor;
use RoayaPay\PlatformCore\Monitoring\ErrorReport;
use RoayaPay\PlatformCore\Monitoring\LogErrorReporter;
use RoayaPay\PlatformCore\Tests\TestCase;
use RoayaPay\PlatformCore\Transactions\TransactionContext;
use RuntimeException;
use Throwable;

final class MonitoringAndLoggingTest extends TestCase
{
    #[Test]
    public function the_logger_supports_all_standard_levels(): void
    {
        $logger = $this->app->make(RoayaPayLogger::class);

        $logger->info('i');
        $logger->warning('w');
        $logger->error('e');
        $logger->critical('c');
        $logger->security('s');

        $levels = array_map(fn ($r) => $r->context['roayapay']['level'], $this->logRecords());
        $this->assertSame(['INFO', 'WARNING', 'ERROR', 'CRITICAL', 'SECURITY'], $levels);
    }

    #[Test]
    public function sensitive_values_are_redacted_from_logs(): void
    {
        $this->app->make(RoayaPayLogger::class)->info('Charge with Bearer abc.def.ghi and card 4111 1111 1111 1111', [
            'password' => 'P@ssw0rd!',
            'otp' => '123456',
            'access_token' => 'tok_live_123',
            'headers' => ['Authorization' => 'Basic dXNlcjpwYXNz', 'Accept' => 'application/json'],
            'card_number' => '5555555555554444',
            'pin' => '9876',
            'cvv' => '123',
            'client_secret' => 'cs_live',
            'private_key' => 'k',
            'nested' => ['userPassword' => 'x', 'note' => 'pan 4012888888881881 used'],
            'amount' => '150.00',
        ]);

        $text = $this->allLogText();

        foreach (['P@ssw0rd!', '123456', 'tok_live_123', 'dXNlcjpwYXNz', '5555555555554444', '9876', 'cs_live', 'abc.def.ghi', '4111 1111 1111 1111', '4012888888881881'] as $secret) {
            $this->assertStringNotContainsString($secret, $text, "Leaked [$secret]");
        }

        $this->assertStringContainsString('150.00', $text);
        $this->assertStringContainsString('application/json', $text);
        $this->assertStringContainsString('************1881', $text);
    }

    #[Test]
    public function logs_carry_request_and_transaction_ids(): void
    {
        $this->app['router']->middleware('roayapay.api')->get('api/log-me', function () {
            app(TransactionContext::class)->begin('txn_abc_123');
            app(RoayaPayLogger::class)->info('payment.created');

            return \RoayaPay\PlatformCore\Response\BuildResponse::setCode('SUCCESS')->getResponse();
        });

        $this->getJson('/api/log-me', [...$this->en(), 'X-Request-Id' => 'req-from-gateway-1']);

        $record = collect($this->logRecords())->first(fn ($r) => $r->message === 'payment.created');
        $this->assertSame('req-from-gateway-1', $record->context['roayapay']['request_id']);
        $this->assertSame('txn_abc_123', $record->context['roayapay']['transaction_id']);
    }

    #[Test]
    public function a_logging_failure_never_breaks_the_request(): void
    {
        config(['roayapay.logging.channel' => 'channel_that_breaks', 'logging.channels.channel_that_breaks' => ['driver' => 'custom', 'via' => 'Nope\\DoesNotExist']]);
        $this->app->forgetScopedInstances();

        $this->app->make(RoayaPayLogger::class)->error('this cannot be written');

        $this->getJson('/api/boom', $this->en())->assertStatus(500)->assertJsonPath('code', 5000);
    }

    #[Test]
    public function a_failing_reporter_does_not_stop_other_reporters(): void
    {
        $collector = new class implements ErrorReporter
        {
            /** @var list<ErrorReport> */
            public array $reports = [];

            public function report(ErrorReport $report, Throwable $original): void
            {
                $this->reports[] = $report;
            }
        };

        $failing = new class implements ErrorReporter
        {
            public function report(ErrorReport $report, Throwable $original): void
            {
                throw new RuntimeException('sentry is down');
            }
        };

        $monitor = new ErrorMonitor($this->app, $this->app->make(\RoayaPay\PlatformCore\Support\Sanitizer::class), [$failing, $collector]);

        $this->assertTrue($monitor->capture(RoayaPayException::internal(previous: new RuntimeException('x'))));
        $this->assertCount(1, $collector->reports);
    }

    #[Test]
    public function the_same_exception_is_reported_only_once(): void
    {
        $monitor = $this->app->make(ErrorMonitor::class);
        $original = new RuntimeException('once');
        $normalized = RoayaPayException::fromThrowable($original);

        $this->assertTrue($monitor->capture($normalized, $original));
        $this->assertFalse($monitor->capture($normalized, $original));
        $this->assertFalse($monitor->capture(RoayaPayException::fromThrowable($original), $original));

        $this->assertCount(1, $this->exceptionLogs());
    }

    #[Test]
    public function reporting_policies_are_respected(): void
    {
        $monitor = $this->app->make(ErrorMonitor::class);

        $this->assertFalse($monitor->capture(RoayaPayException::business('INSUFFICIENT_BALANCE')));
        $this->assertFalse($monitor->capture(RoayaPayException::validation(['a' => 'b'])));
        $this->assertTrue($monitor->capture(RoayaPayException::payment()));
        $this->assertTrue($monitor->capture(RoayaPayException::security()));
        $this->assertTrue($monitor->capture(RoayaPayException::database()));

        $levels = array_map(fn ($r) => $r->context['roayapay']['level'], $this->exceptionLogs());
        $this->assertSame(['WARNING', 'SECURITY', 'CRITICAL'], $levels);
    }

    #[Test]
    public function roayapay_exceptions_in_queues_or_console_use_the_monitor(): void
    {
        report(RoayaPayException::external('PAYMENT_PROVIDER_UNAVAILABLE'));

        $logs = $this->exceptionLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('PAYMENT_PROVIDER_UNAVAILABLE', $logs[0]->context['error']['api_code']);
    }

    #[Test]
    public function non_roayapay_exceptions_outside_api_keep_laravel_reporting(): void
    {
        report(new RuntimeException('plain console failure'));

        $this->assertSame([], $this->exceptionLogs());
        $this->assertStringContainsString('plain console failure', $this->allLogText());
    }

    #[Test]
    public function the_log_reporter_is_registered_by_default(): void
    {
        $this->assertSame([LogErrorReporter::class], config('roayapay.monitoring.reporters'));
        Log::channel('roayapay_test');
    }
}
