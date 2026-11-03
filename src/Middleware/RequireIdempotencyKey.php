<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Middleware;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use RoayaPay\PlatformCore\Response\BuildResponse;
use RoayaPay\PlatformCore\Transactions\IdempotencyManager;
use RoayaPay\PlatformCore\Transactions\TransactionContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a well-formed Idempotency-Key header (alias: roayapay.idempotency)
 * and stores it in the request-scoped TransactionContext. Use it on endpoints
 * that move money, then wrap the operation with IdempotencyManager::run().
 */
final readonly class RequireIdempotencyKey
{
    public function __construct(private Container $container) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $this->container->make('config')->get('roayapay.idempotency.header', 'Idempotency-Key');
        $key = $request->headers->get($header);

        if (! IdempotencyManager::isValidKey($key)) {
            return BuildResponse::setCode('IDEMPOTENCY_KEY_REQUIRED')->getResponse();
        }

        $this->container->make(TransactionContext::class)->setIdempotencyKey((string) $key);

        return $next($request);
    }
}
