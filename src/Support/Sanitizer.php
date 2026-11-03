<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Redacts sensitive data before anything is written to logs, monitors or audit metadata.
 *
 * Two layers:
 *  - key-based: values under sensitive keys (password, otp, token, pin, cvv,
 *    authorization, card_number, private_key, ...) are replaced entirely;
 *  - value-based: card numbers (Luhn-valid PANs), bearer tokens, JWTs and PEM
 *    private keys are masked wherever they appear in strings.
 *
 * Keys are split into words (snake_case, camelCase, kebab-case) so that
 * "footprint" is not mistaken for "otp" while "userOtp" and "otp_code" are caught.
 */
final readonly class Sanitizer
{
    private const int MAX_DEPTH = 8;

    /** Single words that make any key sensitive. */
    private const array SENSITIVE_WORDS = [
        'password', 'passwd', 'pwd', 'passphrase', 'secret', 'token', 'otp', 'pin', 'cvv', 'cvv2', 'cvc',
        'pan', 'authorization', 'auth', 'cookie', 'cookies', 'apikey', 'privatekey', 'credentials', 'credential',
        'iban', 'signature', 'salt', 'mnemonic', 'seed', 'session', 'jwt', 'bearer',
    ];

    /** Word pairs that together make a key sensitive. */
    private const array SENSITIVE_PAIRS = [
        ['card', 'number'], ['card', 'no'], ['card', 'num'], ['card', 'data'], ['card', 'expiry'], ['card', 'exp'],
        ['api', 'key'], ['private', 'key'], ['secret', 'key'], ['access', 'key'], ['encryption', 'key'],
        ['account', 'number'], ['security', 'code'], ['verification', 'code'], ['php', 'pw'],
    ];

    /**
     * @param  list<string>  $extraKeys  Additional normalized keys (lowercase, no separators).
     */
    public function __construct(public string $mask = '[REDACTED]', private array $extraKeys = []) {}

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function sanitize(array $data): array
    {
        return $this->sanitizeArray($data, 0);
    }

    public function isSensitiveKey(string|int $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        $normalized = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));

        if ($normalized === '' ) {
            return false;
        }

        if (in_array($normalized, $this->extraKeys, true) || in_array($normalized, self::SENSITIVE_WORDS, true)) {
            return true;
        }

        $words = $this->words($key);

        if (array_any($words, fn (string $word): bool => in_array($word, self::SENSITIVE_WORDS, true))) {
            return true;
        }

        return array_any(
            self::SENSITIVE_PAIRS,
            fn (array $pair): bool => in_array($pair[0], $words, true) && in_array($pair[1], $words, true),
        );
    }

    /**
     * Masks sensitive patterns inside free text (messages, URLs, exception text).
     */
    public function scrubString(string $value): string
    {
        // PEM private keys.
        $value = (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', $this->mask, $value);

        // Bearer / Basic credentials.
        $value = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/=]+/i', '$1 '.$this->mask, $value);

        // JWTs.
        $value = (string) preg_replace('/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/', $this->mask, $value);

        // key=value / "key": "value" pairs with sensitive keys.
        $value = (string) preg_replace_callback(
            '/(?P<key>[A-Za-z0-9_\-]{2,40})(?P<sep>"?\s*[:=]\s*"?)(?P<val>[^\s"&,;}]+)/',
            fn (array $m): string => $this->isSensitiveKey($m['key']) ? $m['key'].$m['sep'].$this->mask : $m[0],
            $value,
        );

        // Card numbers (13–19 digits, optional spaces/dashes) that pass the Luhn check.
        return (string) preg_replace_callback(
            '/(?<![0-9])(?:[0-9][ -]?){12,18}[0-9](?![0-9])/',
            function (array $m): string {
                $digits = (string) preg_replace('/\D/', '', $m[0]);

                return $this->passesLuhn($digits) ? str_repeat('*', strlen($digits) - 4).substr($digits, -4) : $m[0];
            },
            $value,
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function sanitizeArray(array $data, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['[TRUNCATED]'];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            $clean[$key] = $this->isSensitiveKey($key) ? $this->mask : $this->sanitizeValue($value, $depth + 1);
        }

        return $clean;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            is_string($value) => $this->scrubString($value),
            is_int($value) && strlen((string) $value) >= 13 => $this->scrubString((string) $value),
            is_array($value) => $this->sanitizeArray($value, $depth),
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof Throwable => $value::class,
            $value instanceof Arrayable => $this->sanitizeArray($value->toArray(), $depth),
            $value instanceof JsonSerializable => $this->sanitizeValue($value->jsonSerialize(), $depth),
            $value instanceof Stringable => $this->scrubString((string) $value),
            is_object($value) => '['.$value::class.']',
            default => '['.get_debug_type($value).']',
        };
    }

    /**
     * @return list<string>
     */
    private function words(string $key): array
    {
        $spaced = (string) preg_replace(['/([a-z0-9])([A-Z])/', '/[^A-Za-z0-9]+/'], ['$1 $2', ' '], $key);

        return array_values(array_filter(explode(' ', strtolower($spaced)), fn (string $w): bool => $w !== ''));
    }

    private function passesLuhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
