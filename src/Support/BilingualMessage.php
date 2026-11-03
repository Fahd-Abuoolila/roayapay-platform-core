<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Support;

use InvalidArgumentException;

/**
 * A validated message override that carries a non-empty text for every required language.
 */
final readonly class BilingualMessage
{
    /**
     * @param  array<string, string>  $messages
     */
    private function __construct(public array $messages) {}

    /**
     * @param  array<mixed>  $messages
     * @param  list<string>  $languages
     *
     * @throws InvalidArgumentException
     */
    public static function from(array $messages, array $languages = ['ar', 'en']): self
    {
        foreach ($languages as $language) {
            $value = $messages[$language] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf(
                    'A RoayaPay message override requires a non-empty "%s" string (required languages: %s).',
                    $language,
                    implode(', ', $languages),
                ));
            }
        }

        return new self(array_intersect_key($messages, array_flip($languages)));
    }

    public function in(string $language): string
    {
        return $this->messages[$language]
            ?? throw new InvalidArgumentException(sprintf('No message for language [%s].', $language));
    }
}
