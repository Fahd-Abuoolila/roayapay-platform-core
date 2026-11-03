<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Support;

use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RoayaPay\PlatformCore\Attributes\AllowsNonStandardResponse;

/**
 * Answers "is this route exempt from the response contract?".
 *
 * Exemptions come from config('roayapay.enforcement.allowed_routes') (route
 * names) and from #[AllowsNonStandardResponse]. Attribute lookups are memoized
 * per "Class@method": this singleton only caches immutable class metadata,
 * never request data, so it is safe under Octane.
 */
final class ResponseExemptions
{
    /** @var array<string, string|null> Class@method => reason|null */
    private array $resolved = [];

    /**
     * @param  list<string>  $allowedRoutes
     */
    public function __construct(private readonly array $allowedRoutes = []) {}

    public function reasonFor(?Route $route): ?string
    {
        if ($route === null) {
            return null;
        }

        $name = $route->getName();

        if ($name !== null && in_array($name, $this->allowedRoutes, true)) {
            return 'config: roayapay.enforcement.allowed_routes';
        }

        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return null; // Closure routes cannot carry attributes.
        }

        return array_key_exists($action, $this->resolved)
            ? $this->resolved[$action]
            : $this->resolved[$action] = $this->inspect($action);
    }

    /**
     * Number of memoized actions (used by performance tests).
     */
    public function cachedCount(): int
    {
        return count($this->resolved);
    }

    private function inspect(string $action): ?string
    {
        [$class, $method] = explode('@', $action, 2);

        try {
            $methodAttributes = new ReflectionMethod($class, $method)->getAttributes(AllowsNonStandardResponse::class);

            if ($methodAttributes !== []) {
                return $methodAttributes[0]->newInstance()->reason;
            }

            $classAttributes = new ReflectionClass($class)->getAttributes(AllowsNonStandardResponse::class);

            return $classAttributes !== [] ? $classAttributes[0]->newInstance()->reason : null;
        } catch (ReflectionException) {
            return null;
        }
    }
}
