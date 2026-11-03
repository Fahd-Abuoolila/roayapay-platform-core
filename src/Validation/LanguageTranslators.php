<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Validation;

use Illuminate\Contracts\Translation\Loader;
use InvalidArgumentException;

/**
 * Provides one LanguageTranslator per supported language.
 *
 * Each translator has a fixed locale that is never changed after creation, so
 * sharing them across requests (singleton) is safe: they hold only cached
 * translation lines, not request data. The application's translator and global
 * locale are never touched.
 */
final class LanguageTranslators
{
    /** @var array<string, LanguageTranslator> */
    private array $translators = [];

    /**
     * @param  list<string>  $supported
     */
    public function __construct(private readonly Loader $loader, private readonly array $supported) {}

    public function for(string $language): LanguageTranslator
    {
        if (! in_array($language, $this->supported, true)) {
            throw new InvalidArgumentException(sprintf('Language [%s] is not supported by RoayaPay.', $language));
        }

        return $this->translators[$language] ??= $this->create($language);
    }

    private function create(string $language): LanguageTranslator
    {
        $translator = new LanguageTranslator($this->loader, $language);
        $translator->setFallback($language);

        return $translator;
    }
}
