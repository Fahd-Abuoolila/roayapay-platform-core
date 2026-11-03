<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Exceptions;

use Illuminate\Container\Container;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Http\Request;
use Throwable;

/**
 * Wires the RoayaPay renderer and reporter into Laravel 12's exception handler.
 *
 * Automatic (default): the service provider calls register() when the handler
 * is resolved (config roayapay.exceptions.auto_register = true).
 *
 * Manual (bootstrap/app.php), with auto_register = false:
 *
 *     ->withExceptions(function (Exceptions $exceptions) {
 *         RoayaPayExceptionHandling::configure($exceptions);
 *     })
 *
 * Only API requests are rendered by RoayaPay; web routes keep Laravel's behaviour.
 * The callbacks resolve services from the CURRENT container at call time, which
 * keeps them correct under Octane (where the application is cloned per request).
 */
final class RoayaPayExceptionHandling
{
    public static function configure(Exceptions $exceptions): void
    {
        $exceptions->render(self::renderCallback());
        $exceptions->report(self::reportCallback());
    }

    public static function register(Handler $handler): void
    {
        $handler->renderable(self::renderCallback());
        $handler->reportable(self::reportCallback());
    }

    private static function renderCallback(): \Closure
    {
        return static fn (Throwable $e, Request $request) => Container::getInstance()
            ->make(ExceptionRenderer::class)
            ->render($e, $request);
    }

    private static function reportCallback(): \Closure
    {
        return static fn (Throwable $e) => Container::getInstance()
            ->make(ExceptionReporter::class)
            ->report($e);
    }
}
