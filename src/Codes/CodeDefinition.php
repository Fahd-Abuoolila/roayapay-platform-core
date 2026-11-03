<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Codes;

/**
 * An immutable, validated RoayaPay API code definition.
 */
final readonly class CodeDefinition
{
    /**
     * @param  array<string, string>  $messages  Message per language code.
     */
    public function __construct(
        public string $key,
        public int $code,
        public int $http,
        public bool $status,
        public ?bool $processCompleted,
        public array $messages,
    ) {}

    /**
     * Message in the given language. The registry guarantees every supported language exists.
     */
    public function message(string $language): string
    {
        return $this->messages[$language]
            ?? throw new UnknownApiCodeException(sprintf('Code [%s] has no message for language [%s].', $this->key, $language));
    }

    public function isFailure(): bool
    {
        return $this->http === 500;
    }
}
