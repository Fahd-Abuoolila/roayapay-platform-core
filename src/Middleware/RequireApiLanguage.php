<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Middleware;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use RoayaPay\PlatformCore\Response\BuildResponse;
use RoayaPay\PlatformCore\Support\LanguageResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires "Accept-Language: ar" or "Accept-Language: en" (alias: roayapay.language).
 *
 * - Missing header → LANGUAGE_REQUIRED; unsupported value → LANGUAGE_NOT_SUPPORTED
 *   (both standard RoayaPay responses, HTTP 200). No silent fallback.
 * - The value must be exactly one supported language (case-insensitive);
 *   q-value lists such as "ar,en;q=0.8" are rejected as unsupported.
 * - The validated language is stored in the request-scoped RequestContext.
 *   The global app locale is NOT changed, so nothing leaks between requests
 *   under Octane/FrankenPHP.
 */
final readonly class RequireApiLanguage
{
    public function __construct(private Container $container, private LanguageResolver $languages) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->container->make(RequestContext::class);
        $context->markAsApi();

        $language = $this->languages->normalize($request->headers->get($this->languages->header));

        if ($language === null) {
            return BuildResponse::setCode('LANGUAGE_REQUIRED')->getResponse();
        }

        if (! $this->languages->isSupported($language)) {
            return BuildResponse::setCode('LANGUAGE_NOT_SUPPORTED')->getResponse();
        }

        $context->setLanguage($language);

        $response = $next($request);
        $response->headers->set('Vary', trim(($response->headers->get('Vary') ?? '').', Accept-Language', ', '));

        return $response;
    }
}
