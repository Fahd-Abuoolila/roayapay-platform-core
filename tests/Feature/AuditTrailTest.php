<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RoayaPay\PlatformCore\Audit\AuditActor;
use RoayaPay\PlatformCore\Audit\AuditEvent;
use RoayaPay\PlatformCore\Audit\AuditSubject;
use RoayaPay\PlatformCore\Audit\AuditTrail;
use RoayaPay\PlatformCore\Audit\Contracts\AuditStore;
use RoayaPay\PlatformCore\Audit\Contracts\AuditTrail as AuditTrailContract;
use RoayaPay\PlatformCore\Audit\Stores\InMemoryAuditStore;
use RoayaPay\PlatformCore\Tests\TestCase;
use RoayaPay\PlatformCore\Transactions\TransactionContext;
use RuntimeException;

final class AuditTrailTest extends TestCase
{
    #[Test]
    public function events_are_appended_to_the_database_with_actor_subject_timestamp_and_metadata(): void
    {
        $event = $this->app->make(AuditTrailContract::class)->record(
            'payment.completed',
            AuditSubject::of('payment', 501),
            AuditActor::merchant(77),
            ['amount' => '150.00', 'currency' => 'EGP', 'card_number' => '4111111111111111', 'otp' => '123456'],
            financial: true,
        );

        $row = DB::table('roayapay_audit_events')->first();

        $this->assertSame($event->id, $row->id);
        $this->assertSame('payment.completed', $row->action);
        $this->assertSame('merchant', $row->actor_type);
        $this->assertSame('77', $row->actor_id);
        $this->assertSame('payment', $row->subject_type);
        $this->assertSame('501', $row->subject_id);
        $this->assertEquals(1, $row->financial);
        $this->assertNotEmpty($row->occurred_at);
        $this->assertSame($event->hash(), $row->hash);

        $metadata = json_decode($row->metadata, true);
        $this->assertSame('150.00', $metadata['amount']);
        $this->assertSame('[REDACTED]', $metadata['card_number']);
        $this->assertSame('[REDACTED]', $metadata['otp']);
    }

    #[Test]
    public function events_are_correlated_with_request_and_transaction(): void
    {
        $this->app['router']->middleware('roayapay.api')->post('api/audited', function () {
            app(TransactionContext::class)->begin('txn_audit_1');
            app(AuditTrail::class)->record('balance.adjusted', AuditSubject::of('wallet', 'w_1'), financial: true);

            return \RoayaPay\PlatformCore\Response\BuildResponse::setCode('SUCCESS')->getResponse();
        });

        $this->postJson('/api/audited', [], [...$this->en(), 'X-Request-Id' => 'req-audit-0001'])->assertOk();

        $row = DB::table('roayapay_audit_events')->first();
        $this->assertSame('req-audit-0001', $row->request_id);
        $this->assertSame('txn_audit_1', $row->transaction_id);
        $this->assertSame('system', $row->actor_type, 'Without an authenticated user the actor is the system');
    }

    #[Test]
    public function audit_events_are_separate_from_error_logs(): void
    {
        $this->app->make(AuditTrailContract::class)->record('merchant.updated', AuditSubject::of('merchant', 1));

        $this->assertSame([], $this->logRecords());
    }

    #[Test]
    public function the_contract_and_store_are_append_only(): void
    {
        foreach ([AuditTrailContract::class, AuditStore::class] as $contract) {
            $methods = array_map(fn ($m) => $m->getName(), (new ReflectionClass($contract))->getMethods());

            foreach ($methods as $method) {
                $this->assertDoesNotMatchRegularExpression('/update|delete|remove|modify|edit|purge|forget/i', $method);
            }
        }
    }

    #[Test]
    public function events_are_immutable(): void
    {
        $this->assertTrue((new ReflectionClass(AuditEvent::class))->isReadOnly());
    }

    #[Test]
    public function financial_events_are_never_silently_dropped(): void
    {
        $trail = new AuditTrail($this->failingStore(), $this->app, $this->app->make(\RoayaPay\PlatformCore\Support\Sanitizer::class), $this->app->make(\RoayaPay\PlatformCore\Logging\RoayaPayLogger::class));

        $this->expectException(RuntimeException::class);
        $trail->record('payment.created', AuditSubject::of('payment', 1), financial: true);
    }

    #[Test]
    public function non_financial_failures_are_logged_and_swallowed(): void
    {
        $trail = new AuditTrail($this->failingStore(), $this->app, $this->app->make(\RoayaPay\PlatformCore\Support\Sanitizer::class), $this->app->make(\RoayaPay\PlatformCore\Logging\RoayaPayLogger::class));

        $trail->record('merchant.updated', AuditSubject::of('merchant', 1));

        $this->assertStringContainsString('roayapay.audit.write_failed', $this->allLogText());
    }

    #[Test]
    public function the_store_is_configurable(): void
    {
        $memory = new InMemoryAuditStore();
        $this->app->instance(AuditStore::class, $memory);
        $this->app->forgetScopedInstances();
        $this->app->instance(AuditStore::class, $memory);

        $this->app->make(AuditTrailContract::class)->record('provider.configuration_changed', AuditSubject::of('provider', 'acme'), AuditActor::user(5));

        $this->assertCount(1, $memory->events());
        $this->assertSame('provider.configuration_changed', $memory->events()[0]->action);
        $this->assertSame(5, $memory->events()[0]->actor->id);
    }

    #[Test]
    public function invalid_action_names_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(AuditTrailContract::class)->record('Payment Created', AuditSubject::of('payment', 1));
    }

    private function failingStore(): AuditStore
    {
        return new class implements AuditStore
        {
            public function append(AuditEvent $event): void
            {
                throw new RuntimeException('audit database unavailable');
            }
        };
    }
}
