<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Architecture;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use RoayaPay\PlatformCore\Response\BuildResponse;
use RoayaPay\PlatformCore\Tests\TestCase;
use RoayaPay\PlatformCore\Transactions\TransactionContext;

/**
 * Long-running worker safety (Octane / FrankenPHP / queue workers).
 */
final class NoRequestStateLeakTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    private function packageClasses(): array
    {
        $root = realpath(__DIR__.'/../../src');
        $classes = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'RoayaPay\\PlatformCore\\'.str_replace('/', '\\', $relative);

            if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    #[Test]
    public function the_package_declares_no_static_properties_at_all(): void
    {
        $classes = $this->packageClasses();
        $this->assertGreaterThan(50, count($classes));

        $offenders = [];
        foreach ($classes as $class) {
            foreach (new ReflectionClass($class)->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->getName() === $class) {
                    $offenders[] = $class.'::$'.$property->getName();
                }
            }
        }

        $this->assertSame([], $offenders, 'Static properties can leak state between requests in long-running workers.');
    }

    #[Test]
    public function the_package_never_mutates_the_global_locale(): void
    {
        $offenders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
            if ($file->getExtension() === 'php' && preg_match('/setLocale\s*\(|App::setLocale|app\(\)->setLocale|setlocale\s*\(/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders);
    }

    #[Test]
    public function every_request_object_gets_its_own_context(): void
    {
        $first = Request::create('/api/a');
        $second = Request::create('/api/b');

        $this->app->instance('request', $first);
        $contextA = $this->app->make(RequestContext::class);
        $contextA->setLanguage('ar');
        $contextA->setRequestId('req-aaaaaaaa');
        $this->app->make(TransactionContext::class)->begin('txn_a_1');

        $this->app->instance('request', $second);
        $contextB = $this->app->make(RequestContext::class);

        $this->assertNotSame($contextA, $contextB);
        $this->assertNull($contextB->language);
        $this->assertNull($contextB->requestId);
        $this->assertNull($this->app->make(TransactionContext::class)->transactionId);

        // Same request → same context instance.
        $this->app->instance('request', $first);
        $this->assertSame($contextA, $this->app->make(RequestContext::class));
    }

    #[Test]
    public function context_values_are_write_once_within_a_request(): void
    {
        $context = new RequestContext();
        $context->setLanguage('ar');
        $context->setLanguage('ar'); // idempotent

        $this->expectException(\LogicException::class);
        $context->setLanguage('en');
    }

    #[Test]
    public function language_and_response_data_do_not_leak_across_worker_style_requests(): void
    {
        // Simulates an Octane worker: one application instance, many requests, scoped
        // services flushed between requests exactly as Octane does.
        $expected = [];
        $actual = [];

        foreach (['ar', 'en', 'ar', 'en', 'en', 'ar'] as $i => $language) {
            $response = $this->getJson('/api/context', ['Accept-Language' => $language, 'X-Request-Id' => "worker-req-000$i"]);
            $body = $this->body($response);

            $expected[] = [$language, $language === 'ar' ? 'تم بنجاح' : 'Done', "worker-req-000$i"];
            $actual[] = [$body->data->language, $body->message, $body->data->request_id];

            $this->app->forgetScopedInstances();
        }

        $this->assertSame($expected, $actual);
    }

    #[Test]
    public function builder_state_is_never_shared(): void
    {
        $responses = [];

        foreach (range(1, 20) as $i) {
            $responses[] = BuildResponse::setCode('SUCCESS')->setData(['n' => $i]);
        }

        foreach ($responses as $i => $builder) {
            $this->assertSame(['n' => $i + 1], $builder->getResponse()->getData(true)['data']);
        }
    }

    #[Test]
    public function exception_handler_callbacks_resolve_the_current_container(): void
    {
        // Under Octane the app is cloned per request; callbacks must not capture the boot-time app.
        $source = (string) file_get_contents(__DIR__.'/../../src/Exceptions/RoayaPayExceptionHandling.php');

        $this->assertStringContainsString('Container::getInstance()', $source);
        $this->assertStringNotContainsString('$this->app', $source);
    }
}
