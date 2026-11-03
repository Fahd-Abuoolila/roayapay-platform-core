<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Architecture;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use RoayaPay\PlatformCore\Codes\ApiCodeRegistry;
use RoayaPay\PlatformCore\Middleware\AssignRequestId;
use RoayaPay\PlatformCore\Middleware\EnforceRoayaPayResponse;
use RoayaPay\PlatformCore\Middleware\RequireApiLanguage;
use RoayaPay\PlatformCore\Middleware\RequireIdempotencyKey;
use RoayaPay\PlatformCore\Providers\PlatformCoreServiceProvider;
use RoayaPay\PlatformCore\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function middleware_aliases_and_the_opt_in_group_are_registered(): void
    {
        $router = $this->app['router'];

        $this->assertSame(AssignRequestId::class, $router->getMiddleware()['roayapay.request-id']);
        $this->assertSame(RequireApiLanguage::class, $router->getMiddleware()['roayapay.language']);
        $this->assertSame(EnforceRoayaPayResponse::class, $router->getMiddleware()['roayapay.enforce']);
        $this->assertSame(RequireIdempotencyKey::class, $router->getMiddleware()['roayapay.idempotency']);
        $this->assertSame(PlatformCoreServiceProvider::MIDDLEWARE_GROUP, $router->getMiddlewareGroups()['roayapay.api']);
    }

    #[Test]
    public function global_middleware_and_existing_groups_are_not_altered(): void
    {
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);

        foreach ([AssignRequestId::class, RequireApiLanguage::class, EnforceRoayaPayResponse::class] as $middleware) {
            $this->assertFalse($kernel->hasMiddleware($middleware), "$middleware must not be added globally");
        }

        foreach (['web', 'api'] as $group) {
            foreach ($this->app['router']->getMiddlewareGroups()[$group] ?? [] as $middleware) {
                $this->assertStringNotContainsString('RoayaPay', (string) $middleware);
            }
        }
    }

    #[Test]
    public function config_translations_and_migrations_are_publishable(): void
    {
        $config = ServiceProvider::pathsToPublish(PlatformCoreServiceProvider::class, 'roayapay-config');
        $lang = ServiceProvider::pathsToPublish(PlatformCoreServiceProvider::class, 'roayapay-lang');
        $migrations = ServiceProvider::pathsToPublish(PlatformCoreServiceProvider::class, 'roayapay-migrations');

        $this->assertCount(2, $config);
        $this->assertCount(1, $lang);
        $this->assertCount(2, $migrations);

        foreach ([...array_keys($config), ...array_keys($lang), ...array_keys($migrations)] as $source) {
            $this->assertFileExists($source);
        }
    }

    #[Test]
    public function package_codes_are_merged_with_application_codes(): void
    {
        // An application that publishes and extends the registry keeps the package's required codes.
        config(['roayapay_api_codes' => [
            ...config('roayapay_api_codes'),
            'WALLET_FROZEN' => ['code' => 4200, 'http' => 200, 'status' => false, 'message' => ['ar' => 'المحفظة مجمدة', 'en' => 'Wallet is frozen']],
        ]]);
        $this->app->forgetInstance(ApiCodeRegistry::class);

        $registry = $this->app->make(ApiCodeRegistry::class);
        $this->assertSame(4200, $registry->get('WALLET_FROZEN')->code);
        $this->assertTrue($registry->has('CODE_ERR'));
        $this->assertTrue($registry->get('WALLET_FROZEN')->processCompleted, 'process_completed defaults to true for HTTP 200');
    }

    #[Test]
    public function the_codes_command_lists_and_validates(): void
    {
        $this->assertSame(0, Artisan::call('roayapay:codes'));
        $this->assertStringContainsString('INSUFFICIENT_BALANCE', Artisan::output());

        $this->assertSame(0, Artisan::call('roayapay:codes', ['--validate' => true]));
        $this->assertStringContainsString('is valid', Artisan::output());

        config(['roayapay_api_codes.BROKEN' => ['code' => 1000]]);
        $this->assertSame(1, Artisan::call('roayapay:codes', ['--validate' => true]));
        $this->assertStringContainsString('Duplicate integer code 1000', Artisan::output());
    }

    #[Test]
    public function the_composer_manifest_targets_php_84_and_laravel_12_only(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

        $this->assertSame('roayapay/platform-core', $composer['name']);
        $this->assertSame('^8.4', $composer['require']['php']);
        $this->assertArrayNotHasKey('laravel/framework', $composer['require'], 'Only Illuminate components at runtime');

        foreach ($composer['require'] as $package => $constraint) {
            if (str_starts_with($package, 'illuminate/')) {
                $this->assertSame('^12.0', $constraint, $package);
            }
        }

        $this->assertSame([PlatformCoreServiceProvider::class], $composer['extra']['laravel']['providers']);
        $this->assertStringStartsWith('12.', $this->app->version());
        $this->assertSame(8, PHP_MAJOR_VERSION);
        $this->assertSame(4, PHP_MINOR_VERSION);
    }
}
