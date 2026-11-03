<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Support;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * Resolves objects that must live exactly as long as the current HTTP request.
 *
 * The instance is stored on the Request's attribute bag. A new Request object is
 * created for every request (also under Octane/FrankenPHP), so nothing can leak
 * from one request to the next, even when the container itself is reused.
 */
final class RequestScoped
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  string  $attribute
     * @param  class-string  $detachedHolder  Container-scoped holder used outside HTTP.
     * @return T
     */
    public static function resolve(Container $container, string $class, string $attribute, string $detachedHolder): object
    {
        $request = $container->bound('request') ? $container->make('request') : null;

        if ($request instanceof Request) {
            $instance = $request->attributes->get($attribute);

            if (! $instance instanceof $class) {
                $instance = new $class();
                $request->attributes->set($attribute, $instance);
            }

            return $instance;
        }

        return $container->make($detachedHolder)->instance;
    }
}
