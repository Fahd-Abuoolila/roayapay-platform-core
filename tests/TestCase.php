<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests;

use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Orchestra\Testbench\TestCase as Orchestra;
use RoayaPay\PlatformCore\Providers\PlatformCoreServiceProvider;
use RoayaPay\PlatformCore\Tests\Fixtures\TestRoutes;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PlatformCoreServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        // APP_DEBUG=true on purpose: proves that RoayaPay responses never leak details even in debug mode.
        $app['config']->set('app.debug', true);
        $app['config']->set('app.locale', 'en');

        $app['config']->set('logging.default', 'roayapay_test');
        $app['config']->set('logging.channels.roayapay_test', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
            'level' => 'debug',
        ]);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (['create_roayapay_audit_events_table', 'create_roayapay_idempotency_keys_table'] as $stub) {
            (require __DIR__."/../database/migrations/{$stub}.php.stub")->up();
        }
    }

    protected function defineRoutes($router): void
    {
        TestRoutes::register($router);
    }

    protected function testHandler(): TestHandler
    {
        /** @var TestHandler $handler */
        $handler = Log::channel('roayapay_test')->getLogger()->getHandlers()[0];

        return $handler;
    }

    /**
     * @return list<LogRecord>
     */
    protected function logRecords(): array
    {
        return $this->testHandler()->getRecords();
    }

    protected function allLogText(): string
    {
        return implode("\n", array_map(
            fn (LogRecord $r): string => $r->message.' '.json_encode($r->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->logRecords(),
        ));
    }

    /**
     * @return list<LogRecord>
     */
    protected function exceptionLogs(): array
    {
        return array_values(array_filter(
            $this->logRecords(),
            fn (LogRecord $r): bool => str_starts_with($r->message, 'roayapay.exception'),
        ));
    }

    /**
     * @return array<string, string>
     */
    protected function ar(): array
    {
        return ['Accept-Language' => 'ar', 'Accept' => 'application/json'];
    }

    /**
     * @return array<string, string>
     */
    protected function en(): array
    {
        return ['Accept-Language' => 'en', 'Accept' => 'application/json'];
    }

    /**
     * Decodes the body as objects so that {} and [] can be told apart.
     */
    protected function body(\Illuminate\Testing\TestResponse $response): object
    {
        $decoded = json_decode((string) $response->getContent(), false, flags: JSON_THROW_ON_ERROR);
        $this->assertIsObject($decoded);

        return $decoded;
    }

    protected function assertFourKeys(\Illuminate\Testing\TestResponse $response): object
    {
        $body = $this->body($response);
        $this->assertSame(['status', 'code', 'message', 'data'], array_keys(get_object_vars($body)));
        $this->assertIsBool($body->status);
        $this->assertIsInt($body->code);
        $this->assertIsString($body->message);
        $this->assertTrue(is_object($body->data) || is_array($body->data));

        return $body;
    }
}
