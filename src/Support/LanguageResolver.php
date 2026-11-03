<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use RoayaPay\PlatformCore\Monitoring\RequestContext;

/**
 * Determines the language used to word a RoayaPay response.
 *
 * Order: (1) the language validated by RequireApiLanguage for this request,
 * (2) a supported Accept-Language header on a request that has not passed the
 * middleware yet (e.g. a 404 for an unknown route), (3) the configured fallback,
 * used only to word responses that are produced without a validated language.
 *
 * It never changes the application locale.
 */
final readonly class LanguageResolver
{
    /**
     * @param  list<string>  $supported
     */
    public function __construct(
        private Container $container,
        public array $supported,
        public string $fallback,
        public string $header = 'Accept-Language',
    ) {}

    public function current(): string
    {
        $context = $this->container->make(RequestContext::class);

        if ($context->language !== null) {
            return $context->language;
        }

        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        if ($request instanceof Request) {
            $candidate = $this->normalize($request->headers->get($this->header));

            if ($candidate !== null && $this->isSupported($candidate)) {
                return $candidate;
            }
        }

        return $this->fallback;
    }

    public function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        return $value === '' ? null : $value;
    }

    public function isSupported(string $language): bool
    {
        return in_array($language, $this->supported, true);
    }
}
