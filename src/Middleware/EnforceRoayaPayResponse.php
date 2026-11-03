<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Middleware;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use RoayaPay\PlatformCore\Logging\RoayaPayLogger;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use RoayaPay\PlatformCore\Response\BuildResponse;
use RoayaPay\PlatformCore\Response\RoayaPayJsonResponse;
use RoayaPay\PlatformCore\Support\ResponseExemptions;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Runtime fallback that enforces the RoayaPay response contract on API routes
 * (alias: roayapay.enforce). The primary enforcement is the static scanner in CI.
 *
 * - RoayaPayJsonResponse → passed through.
 * - Files, streams, redirects, HTML and other non-JSON responses → untouched.
 * - Any other JSON (response()->json(), raw arrays, new JsonResponse) →
 *   logged as a contract violation; in "replace" mode replaced by
 *   RESPONSE_CONTRACT_VIOLATION (HTTP 500, process_completed = null, because
 *   the operation may have succeeded – the client must verify its status).
 * - Routes listed in roayapay.enforcement.allowed_routes or annotated with
 *   #[AllowsNonStandardResponse] are exempt.
 *
 * Exceptions are rendered by the RoayaPay renderer before reaching this
 * middleware, so Laravel's exception handling is not affected.
 */
final readonly class EnforceRoayaPayResponse
{
    public function __construct(
        private Container $container,
        private RoayaPayLogger $logger,
        private ResponseExemptions $exemptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->container->make(RequestContext::class)->markAsApi();

        $response = $next($request);

        if ($response instanceof RoayaPayJsonResponse || ! $this->isJson($response)) {
            return $response;
        }

        $exemption = $this->exemptions->reasonFor($request->route());

        if ($exemption !== null) {
            return $response;
        }

        $config = $this->container->make('config');
        $mode = (string) $config->get('roayapay.enforcement.mode', 'replace');

        $this->logger->error('roayapay.response_contract_violation', [
            'route' => $request->route()?->getName() ?? $request->route()?->uri(),
            'action' => $request->route()?->getActionName(),
            'method' => $request->getMethod(),
            'response_class' => $response::class,
            'http_status' => $response->getStatusCode(),
            'mode' => $mode,
        ]);

        if ($mode !== 'replace') {
            return $response;
        }

        $replacement = BuildResponse::setCode((string) $config->get('roayapay.enforcement.replacement_code', 'RESPONSE_CONTRACT_VIOLATION'))
            ->getResponse();

        foreach (['X-Request-Id', (string) $config->get('roayapay.request_id.header', 'X-Request-Id')] as $header) {
            if ($response->headers->has($header)) {
                $replacement->headers->set($header, (string) $response->headers->get($header));
            }
        }

        return $replacement;
    }

    private function isJson(Response $response): bool
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return false;
        }

        if ($response instanceof JsonResponse) {
            return true;
        }

        $type = strtolower((string) $response->headers->get('Content-Type'));

        return str_contains($type, 'application/json') || str_contains($type, '+json');
    }
}
