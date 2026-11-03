<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Support;

use RoayaPay\PlatformCore\Monitoring\RequestContext;

/**
 * Container-scoped holder for the context used outside HTTP requests (console, queue).
 *
 * @internal
 */
final class DetachedRequestContext
{
    public readonly RequestContext $instance;

    public function __construct()
    {
        $this->instance = new RequestContext();
    }
}
