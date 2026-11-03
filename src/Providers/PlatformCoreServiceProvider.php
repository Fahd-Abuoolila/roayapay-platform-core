<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Factory as ValidationFactory;
use InvalidArgumentException;
use RoayaPay\PlatformCore\Audit\AuditTrail;
use RoayaPay\PlatformCore\Audit\Contracts\AuditStore;
use RoayaPay\PlatformCore\Audit\Contracts\AuditTrail as AuditTrailContract;
use RoayaPay\PlatformCore\Audit\Stores\DatabaseAuditStore;
use RoayaPay\PlatformCore\Audit\Stores\LogAuditStore;
use RoayaPay\PlatformCore\Codes\ApiCodeRegistry;
use RoayaPay\PlatformCore\Console\CodesCommand;
use RoayaPay\PlatformCore\Console\ScanResponsesCommand;
use RoayaPay\PlatformCore\Exceptions\ExceptionNormalizer;
use RoayaPay\PlatformCore\Exceptions\ExceptionRenderer;
use RoayaPay\PlatformCore\Exceptions\ExceptionReporter;
use RoayaPay\PlatformCore\Exceptions\RoayaPayExceptionHandling;
use RoayaPay\PlatformCore\Logging\RoayaPayLogger;
use RoayaPay\PlatformCore\Middleware\AssignRequestId;
use RoayaPay\PlatformCore\Middleware\EnforceRoayaPayResponse;
use RoayaPay\PlatformCore\Middleware\RequireApiLanguage;
use RoayaPay\PlatformCore\Middleware\RequireIdempotencyKey;
use RoayaPay\PlatformCore\Monitoring\ErrorMonitor;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use RoayaPay\PlatformCore\Response\ResponseFactory;
use RoayaPay\PlatformCore\Support\ApiRequestDetector;
use RoayaPay\PlatformCore\Support\DetachedRequestContext;
use RoayaPay\PlatformCore\Support\DetachedTransactionContext;
use RoayaPay\PlatformCore\Support\LanguageResolver;
use RoayaPay\PlatformCore\Support\RequestScoped;
use RoayaPay\PlatformCore\Support\ResponseExemptions;
use RoayaPay\PlatformCore\Support\Sanitizer;
use RoayaPay\PlatformCore\Transactions\Contracts\IdempotencyStore;
use RoayaPay\PlatformCore\Transactions\IdempotencyManager;
use RoayaPay\PlatformCore\Transactions\Stores\CacheIdempotencyStore;
use RoayaPay\PlatformCore\Transactions\Stores\DatabaseIdempotencyStore;
use RoayaPay\PlatformCore\Transactions\TransactionContext;
use RoayaPay\PlatformCore\Validation\FrameworkValidatorLocalizer;
use RoayaPay\PlatformCore\Validation\LanguageTranslators;
use RoayaPay\PlatformCore\Validation\ValidationMessageResolver;

/**
 * Registers RoayaPay Platform Core.
 *
 * Lifetimes (Octane / FrankenPHP safe):
 *  - singleton: immutable or class-metadata-only services (code registry,
 *    sanitizer, language translators, normalizer, response exemptions).
 *  - per request object: RequestContext, TransactionContext.
 *  - scoped: everything that touches the container or request data; Laravel
 *    and Octane flush scoped instances between requests/jobs.
 *
 * It never modifies application routes or global middleware. It only adds
 * middleware ALIASES and a "roayapay.api" middleware GROUP you opt into.
 */
final class PlatformCoreServiceProvider extends ServiceProvider
{
    public const array MIDDLEWARE_GROUP = [
        AssignRequestId::class,
        EnforceRoayaPayResponse::class,
        RequireApiLanguage::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/roayapay.php', 'roayapay');
        $this->mergeConfigFrom(__DIR__.'/../../config/roayapay_api_codes.php', 'roayapay_api_codes');

        // ------------------------------------------------------------ immutable singletons
        $this->app->singleton(ApiCodeRegistry::class, fn ($app) => new ApiCodeRegistry(
            (array) $app['config']->get('roayapay_api_codes', []),
            $this->languages($app),
        ));

        $this->app->singleton(Sanitizer::class, fn ($app) => new Sanitizer(
            (string) $app['config']->get('roayapay.sanitizer.mask', '[REDACTED]'),
            array_values((array) $app['config']->get('roayapay.sanitizer.extra_keys', [])),
        ));

        $this->app->singleton(LanguageTranslators::class, fn ($app) => new LanguageTranslators(
            $app['translation.loader'],
            $this->languages($app),
        ));

        $this->app->singleton(ValidationMessageResolver::class, fn ($app) => new ValidationMessageResolver(
            (bool) $app['config']->get('roayapay.validation.all_messages_per_field', false),
            (string) $app['config']->get('roayapay.validation.separator', "\n"),
        ));

        $this->app->singleton(ExceptionNormalizer::class, fn ($app) => new ExceptionNormalizer(
            (array) $app['config']->get('roayapay.exceptions.map', []),
            array_map(fn (string $class) => $app->make($class), (array) $app['config']->get('roayapay.exceptions.mappers', [])),
            (array) $app['config']->get('roayapay.exceptions.model_not_found', []),
        ));

        $this->app->singleton(ResponseExemptions::class, fn ($app) => new ResponseExemptions(
            array_values((array) $app['config']->get('roayapay.enforcement.allowed_routes', [])),
        ));

        // ------------------------------------------------------------ request-bound contexts
        $this->app->scoped(DetachedRequestContext::class);
        $this->app->scoped(DetachedTransactionContext::class);

        $this->app->bind(RequestContext::class, fn (Container $app) => RequestScoped::resolve(
            $app, RequestContext::class, RequestContext::ATTRIBUTE, DetachedRequestContext::class,
        ));

        $this->app->bind(TransactionContext::class, fn (Container $app) => RequestScoped::resolve(
            $app, TransactionContext::class, TransactionContext::ATTRIBUTE, DetachedTransactionContext::class,
        ));

        // ------------------------------------------------------------ scoped services
        $this->app->scoped(LanguageResolver::class, fn ($app) => new LanguageResolver(
            $app,
            $this->languages($app),
            (string) $app['config']->get('roayapay.language.fallback', 'en'),
            (string) $app['config']->get('roayapay.language.header', 'Accept-Language'),
        ));

        $this->app->scoped(ApiRequestDetector::class, fn ($app) => new ApiRequestDetector(
            $app,
            array_values((array) $app['config']->get('roayapay.api.paths', ['api/*'])),
        ));

        $this->app->scoped(ResponseFactory::class, fn ($app) => new ResponseFactory(
            $app,
            $app->make(ApiCodeRegistry::class),
            $app->make(LanguageResolver::class),
            (string) $app['config']->get('roayapay.request_id.header', 'X-Request-Id'),
        ));

        $this->app->scoped(RoayaPayLogger::class, fn ($app) => new RoayaPayLogger(
            $app,
            $app->make(Sanitizer::class),
            $app['config']->get('roayapay.logging.channel'),
            $app['config']->get('roayapay.logging.security_channel'),
        ));

        $this->app->scoped(ErrorMonitor::class, fn ($app) => new ErrorMonitor(
            $app,
            $app->make(Sanitizer::class),
            array_map(fn (string $class) => $app->make($class), (array) $app['config']->get('roayapay.monitoring.reporters', [])),
            (bool) $app['config']->get('roayapay.monitoring.enabled', true),
        ));

        $this->app->scoped(ExceptionRenderer::class);
        $this->app->scoped(ExceptionReporter::class);

        $this->app->scoped(AuditStore::class, fn ($app) => $this->auditStore($app));
        $this->app->scoped(AuditTrailContract::class, fn ($app) => new AuditTrail(
            $app->make(AuditStore::class),
            $app,
            $app->make(Sanitizer::class),
            $app->make(RoayaPayLogger::class),
            (bool) $app['config']->get('roayapay.audit.swallow_non_financial_failures', true),
        ));
        $this->app->alias(AuditTrailContract::class, AuditTrail::class);

        $this->app->scoped(IdempotencyStore::class, fn ($app) => $this->idempotencyStore($app));
        $this->app->scoped(IdempotencyManager::class, fn ($app) => new IdempotencyManager(
            $app->make(IdempotencyStore::class),
            $app,
            (int) $app['config']->get('roayapay.idempotency.lock_ttl', 300),
            (int) $app['config']->get('roayapay.idempotency.retention', 86400),
        ));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'roayapay');

        // The language-bound translators share the LOADER (not the app translator), so register
        // the namespace on the loader itself; loadTranslationsFrom() only does so once the
        // application translator happens to be resolved.
        $this->callAfterResolving('translation.loader', function ($loader): void {
            $loader->addNamespace('roayapay', __DIR__.'/../../lang');
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/roayapay.php' => config_path('roayapay.php'),
                __DIR__.'/../../config/roayapay_api_codes.php' => config_path('roayapay_api_codes.php'),
            ], 'roayapay-config');

            $this->publishes([
                __DIR__.'/../../lang' => $this->app->langPath('vendor/roayapay'),
            ], 'roayapay-lang');

            $this->publishes([
                __DIR__.'/../../database/migrations/create_roayapay_audit_events_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_roayapay_audit_events_table.php'),
                __DIR__.'/../../database/migrations/create_roayapay_idempotency_keys_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time() + 1).'_create_roayapay_idempotency_keys_table.php'),
            ], 'roayapay-migrations');

            $this->commands([ScanResponsesCommand::class, CodesCommand::class]);
        }

        $this->registerMiddleware($this->app->make(Router::class));

        if ($this->app['config']->get('roayapay.exceptions.auto_register', true)) {
            $this->callAfterResolving(ExceptionHandler::class, function ($handler): void {
                if ($handler instanceof Handler) {
                    RoayaPayExceptionHandling::register($handler);
                }
            });
        }

        if ($this->app['config']->get('roayapay.validation.localize_framework_validator', true)) {
            $this->callAfterResolving('validator', function ($factory): void {
                if ($factory instanceof ValidationFactory) {
                    FrameworkValidatorLocalizer::install($factory);
                }
            });
        }
    }

    private function registerMiddleware(Router $router): void
    {
        $router->aliasMiddleware('roayapay.request-id', AssignRequestId::class);
        $router->aliasMiddleware('roayapay.language', RequireApiLanguage::class);
        $router->aliasMiddleware('roayapay.enforce', EnforceRoayaPayResponse::class);
        $router->aliasMiddleware('roayapay.idempotency', RequireIdempotencyKey::class);
        $router->middlewareGroup('roayapay.api', self::MIDDLEWARE_GROUP);
    }

    /**
     * @return list<string>
     */
    private function languages($app): array
    {
        return array_values((array) $app['config']->get('roayapay.language.supported', ['ar', 'en']));
    }

    private function auditStore($app): AuditStore
    {
        $store = $app['config']->get('roayapay.audit.store', 'database');

        return match ($store) {
            'database' => new DatabaseAuditStore(
                $app['db']->connection($app['config']->get('roayapay.audit.connection')),
                (string) $app['config']->get('roayapay.audit.table', 'roayapay_audit_events'),
            ),
            'log' => new LogAuditStore($app['log'], $app['config']->get('roayapay.audit.log_channel')),
            default => is_string($store) && is_a($store, AuditStore::class, true)
                ? $app->make($store)
                : throw new InvalidArgumentException('Invalid roayapay.audit.store configuration.'),
        };
    }

    private function idempotencyStore($app): IdempotencyStore
    {
        $store = $app['config']->get('roayapay.idempotency.store', 'database');

        return match ($store) {
            'database' => new DatabaseIdempotencyStore(
                $app['db']->connection($app['config']->get('roayapay.idempotency.connection')),
                (string) $app['config']->get('roayapay.idempotency.table', 'roayapay_idempotency_keys'),
            ),
            'cache' => new CacheIdempotencyStore($app['cache']->store($app['config']->get('roayapay.idempotency.cache_store'))),
            default => is_string($store) && is_a($store, IdempotencyStore::class, true)
                ? $app->make($store)
                : throw new InvalidArgumentException('Invalid roayapay.idempotency.store configuration.'),
        };
    }
}
