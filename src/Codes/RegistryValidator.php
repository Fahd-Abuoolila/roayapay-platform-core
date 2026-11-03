<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Codes;

/**
 * Validates the raw code registry configuration. Returns a list of human-readable errors.
 */
final class RegistryValidator
{
    public const string KEY_PATTERN = '/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/';

    public const array ALLOWED_HTTP = [200, 500];

    private const array REQUIRED_FIELDS = ['code', 'http', 'status', 'message'];

    /**
     * Codes the package itself depends on.
     */
    public const array REQUIRED_CODES = [
        'SUCCESS', 'VALIDATION_ERROR', 'CODE_ERR', 'LANGUAGE_REQUIRED', 'LANGUAGE_NOT_SUPPORTED',
        'UNAUTHORIZED', 'FORBIDDEN', 'NOT_FOUND', 'RESPONSE_CONTRACT_VIOLATION',
    ];

    /**
     * @param  array<mixed>  $definitions
     * @param  list<string>  $languages
     * @return list<string>
     */
    public function validate(array $definitions, array $languages): array
    {
        $errors = [];
        $seenCodes = [];

        foreach ($definitions as $key => $definition) {
            if (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key)) {
                $errors[] = sprintf('Invalid symbolic name [%s]: use UPPER_SNAKE_CASE (e.g. INSUFFICIENT_BALANCE).', (string) $key);

                continue;
            }

            if (! is_array($definition)) {
                $errors[] = sprintf('[%s] must be an array.', $key);

                continue;
            }

            foreach (self::REQUIRED_FIELDS as $field) {
                if (! array_key_exists($field, $definition)) {
                    $errors[] = sprintf('[%s] is missing required field "%s".', $key, $field);
                }
            }

            if (array_key_exists('code', $definition)) {
                $code = $definition['code'];
                if (! is_int($code) || $code <= 0) {
                    $errors[] = sprintf('[%s] "code" must be a positive integer.', $key);
                } elseif (isset($seenCodes[$code])) {
                    $errors[] = sprintf('Duplicate integer code %d used by [%s] and [%s].', $code, $seenCodes[$code], $key);
                } else {
                    $seenCodes[$code] = $key;
                }
            }

            if (array_key_exists('http', $definition)) {
                if (! is_int($definition['http'])) {
                    $errors[] = sprintf('[%s] "http" must be an integer.', $key);
                } elseif (! in_array($definition['http'], self::ALLOWED_HTTP, true)) {
                    $errors[] = sprintf('[%s] has invalid HTTP status %d: the RoayaPay policy allows only 200 and 500.', $key, $definition['http']);
                }
            }

            if (array_key_exists('status', $definition) && ! is_bool($definition['status'])) {
                $errors[] = sprintf('[%s] "status" must be a boolean.', $key);
            }

            if (array_key_exists('process_completed', $definition)
                && ! is_bool($definition['process_completed']) && $definition['process_completed'] !== null) {
                $errors[] = sprintf('[%s] "process_completed" must be true, false or null.', $key);
            }

            if (($definition['status'] ?? null) === true && ($definition['http'] ?? null) === 500) {
                $errors[] = sprintf('[%s] cannot have status=true with HTTP 500.', $key);
            }

            if (array_key_exists('message', $definition)) {
                $message = $definition['message'];
                if (! is_array($message)) {
                    $errors[] = sprintf('[%s] "message" must be an array of language => text.', $key);
                } else {
                    foreach ($languages as $language) {
                        if (! isset($message[$language]) || ! is_string($message[$language]) || trim($message[$language]) === '') {
                            $errors[] = sprintf('[%s] is missing a non-empty "%s" message.', $key, $language);
                        }
                    }
                }
            }

            $unknown = array_diff(array_keys($definition), [...self::REQUIRED_FIELDS, 'process_completed']);
            if ($unknown !== []) {
                $errors[] = sprintf('[%s] has unknown field(s): %s.', $key, implode(', ', $unknown));
            }
        }

        foreach (self::REQUIRED_CODES as $required) {
            if (! array_key_exists($required, $definitions)) {
                $errors[] = sprintf('Required package code [%s] is missing.', $required);
            }
        }

        return $errors;
    }
}
