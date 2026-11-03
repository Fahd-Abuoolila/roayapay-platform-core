<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Validation;

use Illuminate\Translation\Translator;
use Override;

/**
 * A translator permanently bound to ONE language, used for API validation.
 *
 * - Its locale and fallback are the same language, so an Arabic request can
 *   never silently receive an English message.
 * - "validation.<rule>" lines resolve from roayapay::validation first (the
 *   RoayaPay house style), then from the application's validation.php.
 * - "validation.attributes" merges roayapay::fields (package + published
 *   overrides) under the application's own attributes (application wins).
 * - "validation.custom" (application-defined per-field messages) is untouched.
 *
 * The shared application translator and the global locale are never modified.
 */
final class LanguageTranslator extends Translator
{
    #[Override]
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        if (! is_string($key) || ! str_starts_with($key, 'validation.')) {
            return parent::get($key, $replace, $locale, $fallback);
        }

        if ($key === 'validation.attributes') {
            $package = parent::get('roayapay::fields', [], $locale, $fallback);
            $app = parent::get($key, $replace, $locale, $fallback);

            return array_replace(is_array($package) ? $package : [], is_array($app) ? $app : []);
        }

        if (str_starts_with($key, 'validation.custom') || str_starts_with($key, 'validation.values')) {
            return parent::get($key, $replace, $locale, $fallback);
        }

        $packageKey = 'roayapay::'.$key;
        $line = parent::get($packageKey, $replace, $locale, $fallback);

        if ($line !== $packageKey) {
            return $line;
        }

        $line = parent::get($key, $replace, $locale, $fallback);

        if ($line !== $key) {
            return $line;
        }

        // Unknown rule: a generic message in the SAME language, never another language.
        $generic = parent::get('roayapay::messages.validation_fallback', $replace, $locale, $fallback);

        return $generic === 'roayapay::messages.validation_fallback' ? $key : $generic;
    }
}
