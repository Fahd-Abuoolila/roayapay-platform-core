<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Exceptions;

use Illuminate\Contracts\Container\Container;
use RoayaPay\PlatformCore\Monitoring\ErrorMonitor;
use RoayaPay\PlatformCore\Support\ApiRequestDetector;
use Throwable;

/**
 * Hooked into Laravel's report() pipeline.
 *
 * - RoayaPayException (any context, incl. queues/console) and any exception on an
 *   API request: normalized, reported by the ErrorMonitor according to policy,
 *   and Laravel's default logging is stopped (return false) to avoid duplicates.
 * - Other exceptions (web routes, console) keep Laravel's default reporting.
 */
final readonly class ExceptionReporter
{
    public function __construct(
        private Container $container,
        private ExceptionNormalizer $normalizer,
        private ApiRequestDetector $detector,
    ) {}

    /**
     * @return false|null false = handled (stop default reporting), null = let Laravel continue.
     */
    public function report(Throwable $exception): ?bool
    {
        if (! $exception instanceof RoayaPayException && ! $this->detector->isApi()) {
            return null;
        }

        try {
            $this->container->make(ErrorMonitor::class)->capture($this->normalizer->normalize($exception), $exception);
        } catch (Throwable) {
            return null; // Let Laravel's own reporter try.
        }

        return false;
    }
}
