<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use RoayaPay\PlatformCore\Monitoring\RequestContext;

/**
 * Decides whether a request is a RoayaPay API request.
 *
 * True when a RoayaPay middleware marked the request, or when its path matches
 * one of config('roayapay.api.paths'). Everything else (web routes) keeps
 * Laravel's default behaviour.
 */
final readonly class ApiRequestDetector
{
    /**
     * @param  list<string>  $paths
     */
    public function __construct(private Container $container, private array $paths) {}

    public function isApi(?Request $request = null): bool
    {
        $request ??= $this->currentRequest();

        if ($request === null) {
            return false;
        }

        $context = $request->attributes->get(RequestContext::ATTRIBUTE);

        if ($context instanceof RequestContext && $context->isApi) {
            return true;
        }

        return $this->paths !== [] && $request->is(...$this->paths);
    }

    private function currentRequest(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        // The console binds a synthetic request; it is never an API request.
        if ($request instanceof Request && $this->container->runningInConsole() && $request->server('REQUEST_METHOD') === null) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }
}
