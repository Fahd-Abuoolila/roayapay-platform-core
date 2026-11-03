<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Validation;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;
use ReflectionProperty;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use RoayaPay\PlatformCore\Support\ApiRequestDetector;

/**
 * Makes Validator::make(), $request->validate() and FormRequests produce messages
 * in the validated request language on API requests – without touching the
 * shared translator or the application locale.
 *
 * It installs a validator resolver on the framework factory (once, at boot).
 * For each new validator on an API request with a validated language, the
 * validator receives the language-bound LanguageTranslator. Any resolver the
 * application already installed is preserved and wrapped. Web requests and
 * console validation are left untouched.
 */
final class FrameworkValidatorLocalizer
{
    public static function install(Factory $factory): void
    {
        $previous = self::existingResolver($factory);

        $factory->resolver(static function ($translator, array $data, array $rules, array $messages, array $attributes) use ($previous) {
            $validator = $previous !== null
                ? $previous($translator, $data, $rules, $messages, $attributes)
                : new Validator($translator, $data, $rules, $messages, $attributes);

            // Resolve from the CURRENT container (correct under Octane's per-request sandbox).
            $container = Container::getInstance();
            $context = $container->make(RequestContext::class);

            if ($context->language !== null
                && $validator instanceof Validator
                && $container->make(ApiRequestDetector::class)->isApi()) {
                $validator->setTranslator($container->make(LanguageTranslators::class)->for($context->language));
            }

            return $validator;
        });
    }

    private static function existingResolver(Factory $factory): ?Closure
    {
        $property = new ReflectionProperty(Factory::class, 'resolver');
        $resolver = $property->getValue($factory);

        return $resolver instanceof Closure ? $resolver : null;
    }
}
