<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Validation;

use Illuminate\Support\Str;

/**
 * Resolves a human-readable field name in a given language.
 *
 * Lookup order: application validation.attributes → roayapay::fields (package
 * defaults, extendable by publishing lang/vendor/roayapay/{lang}/fields.php)
 * → nested last segment (items.0.amount → amount) → humanized key.
 */
final readonly class FieldNameResolver
{
    public function __construct(private LanguageTranslators $translators) {}

    public function resolve(string $field, string $language): string
    {
        $attributes = $this->translators->for($language)->get('validation.attributes');
        $attributes = is_array($attributes) ? $attributes : [];

        if (isset($attributes[$field]) && is_string($attributes[$field])) {
            return $attributes[$field];
        }

        $last = Str::afterLast($field, '.');

        if (isset($attributes[$last]) && is_string($attributes[$last])) {
            return $attributes[$last];
        }

        return str_replace('_', ' ', Str::snake($last));
    }
}
