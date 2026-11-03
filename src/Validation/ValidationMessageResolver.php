<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Validation;

use Illuminate\Contracts\Support\MessageBag as MessageBagContract;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Turns validation errors into the single RoayaPay message string.
 *
 * - One message per field by default (no redundant messages for the same field).
 * - Duplicate identical messages are removed.
 * - Messages are joined with "\n".
 *
 * The messages are expected to already be in the request language; this is
 * guaranteed when the validator was created by ApiValidator or by the
 * framework validator while localize_framework_validator is enabled.
 */
final readonly class ValidationMessageResolver
{
    public function __construct(
        private bool $allMessagesPerField = false,
        private string $separator = "\n",
    ) {}

    /**
     * Normalizes every supported error representation to a MessageBag.
     *
     * @param  MessageBagContract|ValidatorContract|ValidationException|array<string, string|list<string>>  $errors
     */
    public function toMessageBag(MessageBagContract|ValidatorContract|ValidationException|array $errors): MessageBag
    {
        return match (true) {
            $errors instanceof ValidationException => new MessageBag($errors->validator->errors()->messages()),
            $errors instanceof ValidatorContract => new MessageBag($errors->errors()->messages()),
            $errors instanceof MessageBag => $errors,
            $errors instanceof MessageBagContract => new MessageBag($errors->messages()),
            default => $this->fromArray($errors),
        };
    }

    /**
     * @param  MessageBagContract|ValidatorContract|ValidationException|array<string, string|list<string>>  $errors
     */
    public function format(MessageBagContract|ValidatorContract|ValidationException|array $errors): string
    {
        $lines = [];

        foreach ($this->toMessageBag($errors)->messages() as $messages) {
            $messages = array_values(array_filter((array) $messages, fn ($m): bool => is_string($m) && trim($m) !== ''));

            if ($messages === []) {
                continue;
            }

            foreach ($this->allMessagesPerField ? $messages : [$messages[0]] as $message) {
                $lines[] = trim($message);
            }
        }

        return implode($this->separator, array_values(array_unique($lines)));
    }

    /**
     * @param  array<mixed>  $errors
     */
    private function fromArray(array $errors): MessageBag
    {
        $bag = new MessageBag();

        foreach ($errors as $field => $messages) {
            if (! is_string($field)) {
                throw new InvalidArgumentException('Validation errors must be keyed by field name.');
            }

            foreach ((array) $messages as $message) {
                if (! is_string($message)) {
                    throw new InvalidArgumentException('Validation error messages must be strings.');
                }
                $bag->add($field, $message);
            }
        }

        return $bag;
    }
}
