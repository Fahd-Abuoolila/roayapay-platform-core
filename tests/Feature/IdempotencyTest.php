<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RoayaPay\PlatformCore\Transactions\Contracts\IdempotencyStore;
use RoayaPay\PlatformCore\Transactions\IdempotencyState;
use RoayaPay\PlatformCore\Transactions\Stores\CacheIdempotencyStore;
use RoayaPay\PlatformCore\Transactions\Stores\DatabaseIdempotencyStore;
use RoayaPay\PlatformCore\Transactions\StoredResult;
use RoayaPay\PlatformCore\Tests\Fixtures\PaymentCounter;
use RoayaPay\PlatformCore\Tests\TestCase;

final class IdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PaymentCounter::$calls = 0;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function stores(): array
    {
        return ['database' => ['database'], 'cache' => ['cache']];
    }

    private function useStore(string $store): void
    {
        config(['roayapay.idempotency.store' => $store]);
        $this->app->forgetScopedInstances();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function pay(array $body = ['amount' => '100.00'], string $key = 'idem-key-0001', string $language = 'en'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/payments', $body, ['Accept-Language' => $language, 'Idempotency-Key' => $key]);
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_repeated_request_is_replayed_not_re_executed(string $store): void
    {
        $this->useStore($store);

        $first = $this->pay();
        $second = $this->pay(language: 'ar');

        $first->assertOk()->assertJsonPath('data.payment_id', 'pay_1');
        $second->assertOk()->assertJsonPath('data.payment_id', 'pay_1')->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame('تم بنجاح', $this->body($second)->message, 'Replays are rendered in the replaying request language');
        $this->assertSame(1, PaymentCounter::$calls);
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_different_payload_with_the_same_key_is_a_conflict(string $store): void
    {
        $this->useStore($store);

        $this->pay(['amount' => '100.00']);
        $this->pay(['amount' => '999.00'])->assertOk()->assertJsonPath('code', 4090);

        $this->assertSame(1, PaymentCounter::$calls);
    }

    #[Test]
    #[DataProvider('stores')]
    public function payload_key_order_does_not_matter(string $store): void
    {
        $this->useStore($store);

        $this->pay(['amount' => '100.00', 'currency' => 'EGP']);
        $this->pay(['currency' => 'EGP', 'amount' => '100.00'])->assertOk()->assertJsonPath('data.payment_id', 'pay_1');
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_known_business_failure_is_stored_and_replayed(string $store): void
    {
        $this->useStore($store);

        $this->pay(['outcome' => 'insufficient'])->assertOk()->assertJsonPath('code', 4001);
        $this->pay(['outcome' => 'insufficient'])->assertOk()->assertJsonPath('code', 4001)->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, PaymentCounter::$calls);
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_validation_failure_releases_the_key(string $store): void
    {
        $this->useStore($store);

        $this->pay(['outcome' => 'invalid'])->assertOk()->assertJsonPath('code', 2000);
        $this->pay(['outcome' => 'invalid'])->assertOk()->assertJsonPath('code', 2000);

        $this->assertSame(2, PaymentCounter::$calls, 'Nothing happened, so the client may retry');
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_crash_is_never_treated_as_a_failure_the_outcome_is_unknown(string $store): void
    {
        $this->useStore($store);

        $this->pay(['outcome' => 'crash'])->assertStatus(500)->assertJsonPath('code', 5000);

        $retry = $this->pay(['outcome' => 'crash']);
        $retry->assertStatus(500)->assertJsonPath('code', 5010);
        $this->assertStringContainsString('verify', $this->body($retry)->message);
        $this->assertSame(1, PaymentCounter::$calls, 'The operation must not run again until verified');
    }

    #[Test]
    #[DataProvider('stores')]
    public function a_concurrent_in_progress_request_is_rejected(string $store): void
    {
        $this->useStore($store);
        $storeInstance = $this->app->make(IdempotencyStore::class);

        $claim = $storeInstance->claim('payments.create', 'idem-key-0001', $this->fingerprint(['amount' => '100.00']), 300, 86400);
        $this->assertSame(IdempotencyState::ACQUIRED, $claim->state);

        $this->pay()->assertOk()->assertJsonPath('code', 4091);
        $this->assertSame(0, PaymentCounter::$calls);
    }

    #[Test]
    #[DataProvider('stores')]
    public function an_expired_lock_becomes_outcome_unknown_not_re_acquired(string $store): void
    {
        $this->useStore($store);
        $storeInstance = $this->app->make(IdempotencyStore::class);

        $storeInstance->claim('scope', 'key-expired-1', 'fp', 1, 86400);
        $this->travel(5)->seconds();

        $again = $storeInstance->claim('scope', 'key-expired-1', 'fp', 1, 86400);
        $this->assertSame(IdempotencyState::OUTCOME_UNKNOWN, $again->state);

        // And it stays unknown (never silently re-acquired) on later attempts.
        $this->assertSame(IdempotencyState::OUTCOME_UNKNOWN, $storeInstance->claim('scope', 'key-expired-1', 'fp', 1, 86400)->state);
    }

    #[Test]
    #[DataProvider('stores')]
    public function only_the_owner_can_complete_a_claim(string $store): void
    {
        $this->useStore($store);
        $storeInstance = $this->app->make(IdempotencyStore::class);

        $claim = $storeInstance->claim('scope', 'owner-key-1', 'fp', 300, 86400);
        $storeInstance->complete('scope', 'owner-key-1', 'not-the-owner', new StoredResult('SUCCESS'), 86400);

        $this->assertSame(IdempotencyState::IN_PROGRESS, $storeInstance->claim('scope', 'owner-key-1', 'fp', 300, 86400)->state);

        $storeInstance->complete('scope', 'owner-key-1', (string) $claim->ownerToken, new StoredResult('SUCCESS', ['x' => 1]), 86400);
        $replay = $storeInstance->claim('scope', 'owner-key-1', 'fp', 300, 86400);

        $this->assertSame(IdempotencyState::REPLAY, $replay->state);
        $this->assertSame(['x' => 1], $replay->result?->data);
    }

    #[Test]
    public function a_missing_or_malformed_key_is_rejected_by_the_middleware(): void
    {
        $this->postJson('/api/payments', [], $this->en())->assertOk()->assertJsonPath('code', 2003);
        $this->postJson('/api/payments', [], [...$this->en(), 'Idempotency-Key' => 'bad key!'])->assertOk()->assertJsonPath('code', 2003);
        $this->assertSame(0, PaymentCounter::$calls);
    }

    #[Test]
    public function concurrent_processes_cannot_both_acquire_the_same_key(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the multi-process test.');
        }

        $file = tempnam(sys_get_temp_dir(), 'roayapay-idem-').'.sqlite';
        touch($file);
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('CREATE TABLE roayapay_idempotency_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, scope TEXT, idempotency_key TEXT, fingerprint TEXT, status TEXT, owner_token TEXT, result TEXT, locked_until TEXT, expires_at TEXT, created_at TEXT, updated_at TEXT, UNIQUE(scope, idempotency_key))');
        unset($pdo);

        $workers = 8;
        $results = [];
        $pids = [];

        for ($i = 0; $i < $workers; $i++) {
            $out = "$file.$i";
            $pid = pcntl_fork();

            if ($pid === 0) {
                // Child: brand-new connection, race for the same key.
                $capsule = new Capsule();
                $capsule->addConnection(['driver' => 'sqlite', 'database' => $file, 'busy_timeout' => 5000]);
                $store = new DatabaseIdempotencyStore($capsule->getConnection());
                usleep(random_int(0, 2000));
                $state = $store->claim('payments.create', 'race-key-0001', 'same-fingerprint', 300, 86400)->state->value;
                file_put_contents($out, $state);
                // Terminate immediately so the child never runs PHPUnit's shutdown handlers.
                posix_kill(posix_getpid(), SIGKILL);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        for ($i = 0; $i < $workers; $i++) {
            $results[] = (string) @file_get_contents("$file.$i");
            @unlink("$file.$i");
        }
        @unlink($file);

        $counts = array_count_values($results);
        $this->assertSame(1, $counts['acquired'] ?? 0, 'Exactly one process may acquire the key: '.json_encode($results));
        $this->assertSame($workers - 1, $counts['in_progress'] ?? 0);
    }

    #[Test]
    public function the_cache_store_requires_lock_support(): void
    {
        $this->assertInstanceOf(CacheIdempotencyStore::class, new CacheIdempotencyStore($this->app['cache']->store('array')));
    }

    #[Test]
    public function rows_are_written_to_the_configured_table(): void
    {
        $this->pay();

        $row = DB::table('roayapay_idempotency_keys')->first();
        $this->assertSame('completed', $row->status);
        $this->assertSame('payments.create', $row->scope);
        $this->assertStringContainsString('"code":"SUCCESS"', $row->result);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        return $this->app->make(\RoayaPay\PlatformCore\Transactions\IdempotencyManager::class)->fingerprint('payments.create', $payload);
    }
}
