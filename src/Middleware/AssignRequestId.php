<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Middleware;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as LogContext;
use Illuminate\Support\Str;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a request ID (alias: roayapay.request-id).
 *
 * - Accepts an incoming X-Request-Id only when trusted and well-formed
 *   (config roayapay.request_id.pattern); otherwise generates a UUIDv7.
 * - Stores it in the request-scoped RequestContext and in Laravel's log
 *   Context, so every log line of the request carries it.
 * - Returns it in the X-Request-Id response header (never in the JSON body).
 *
 * Safe for web routes too; you may prepend it globally.
 */
final readonly class AssignRequestId
{
    public function __construct(private Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->container->make('config');
        $header = (string) $config->get('roayapay.request_id.header', 'X-Request-Id');

        $context = $this->container->make(RequestContext::class);

        if ($context->requestId === null) {
            $context->setRequestId($this->resolveId($request, $header, $config->get('roayapay.request_id')));
        }

        if ($this->container->bound(LogContext::class)) {
            $this->container->make(LogContext::class)->add('request_id', $context->requestId);
        }

        $response = $next($request);

        $response->headers->set($header, (string) $context->requestId);

        return $response;
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    private function resolveId(Request $request, string $header, ?array $config): string
    {
        $incoming = $request->headers->get($header);
        $pattern = (string) ($config['pattern'] ?? '/^[A-Za-z0-9][A-Za-z0-9._:\-]{7,127}$/');

        if (($config['trust_incoming'] ?? true) && is_string($incoming) && preg_match($pattern, $incoming) === 1) {
            return $incoming;
        }

        return (string) Str::uuid7();
    }
}
