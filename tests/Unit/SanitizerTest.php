<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RoayaPay\PlatformCore\Support\Sanitizer;

final class SanitizerTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function keys(): array
    {
        return [
            'password' => ['password', true],
            'password_confirmation' => ['password_confirmation', true],
            'camel password' => ['newPassword', true],
            'otp' => ['otp', true],
            'otp code' => ['otp_code', true],
            'pin' => ['pin', true],
            'card pin' => ['cardPin', true],
            'cvv' => ['cvv', true],
            'card number' => ['card_number', true],
            'card number camel' => ['cardNumber', true],
            'pan' => ['pan', true],
            'token' => ['token', true],
            'access token' => ['access_token', true],
            'authorization' => ['Authorization', true],
            'api key' => ['x-api-key', true],
            'private key' => ['private_key', true],
            'client secret' => ['client_secret', true],
            'cookie' => ['Cookie', true],
            'iban' => ['iban', true],
            'amount' => ['amount', false],
            'footprint (contains otp letters)' => ['footprint', false],
            'spinner (contains pin letters)' => ['spinner', false],
            'merchant id' => ['merchant_id', false],
            'currency' => ['currency', false],
            'company' => ['company', false],
            'list index' => ['0', false],
        ];
    }

    #[Test]
    #[DataProvider('keys')]
    public function it_classifies_sensitive_keys(string $key, bool $sensitive): void
    {
        $this->assertSame($sensitive, new Sanitizer()->isSensitiveKey($key), $key);
    }

    #[Test]
    public function it_redacts_nested_structures_and_keeps_safe_values(): void
    {
        $clean = new Sanitizer()->sanitize([
            'user' => ['email' => 'a@b.co', 'password' => 'x', 'profile' => ['pin' => '1234']],
            'amount' => 150.5,
            'items' => [['card_number' => '4111111111111111', 'qty' => 2]],
        ]);

        $this->assertSame('a@b.co', $clean['user']['email']);
        $this->assertSame('[REDACTED]', $clean['user']['password']);
        $this->assertSame('[REDACTED]', $clean['user']['profile']['pin']);
        $this->assertSame(150.5, $clean['amount']);
        $this->assertSame('[REDACTED]', $clean['items'][0]['card_number']);
        $this->assertSame(2, $clean['items'][0]['qty']);
    }

    #[Test]
    public function it_masks_secrets_inside_free_text(): void
    {
        $sanitizer = new Sanitizer();

        $text = $sanitizer->scrubString(
            'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.sig and card 4111-1111-1111-1111 order 1234567890123 password=hunter2 '
            ."-----BEGIN RSA PRIVATE KEY-----\nMIIabc\n-----END RSA PRIVATE KEY-----"
        );

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $text);
        $this->assertStringNotContainsString('4111-1111-1111-1111', $text);
        $this->assertStringContainsString('************1111', $text);
        $this->assertStringContainsString('1234567890123', $text, 'Non-Luhn numbers (e.g. order IDs) are kept');
        $this->assertStringNotContainsString('hunter2', $text);
        $this->assertStringNotContainsString('MIIabc', $text);
    }

    #[Test]
    public function extra_keys_and_custom_mask_are_supported(): void
    {
        $clean = new Sanitizer('***', ['nationalid'])->sanitize(['national_id' => '29901011234567', 'name' => 'Ali']);

        $this->assertSame('***', $clean['national_id']);
        $this->assertSame('Ali', $clean['name']);
    }

    #[Test]
    public function objects_are_never_dumped(): void
    {
        $clean = new Sanitizer()->sanitize(['service' => new \ArrayObject(), 'when' => new \DateTimeImmutable('2026-01-01T00:00:00Z')]);

        $this->assertSame('[ArrayObject]', $clean['service']);
        $this->assertStringStartsWith('2026-01-01', $clean['when']);
    }

    #[Test]
    public function deep_structures_are_truncated(): void
    {
        $deep = ['v' => 'x'];
        for ($i = 0; $i < 20; $i++) {
            $deep = ['n' => $deep];
        }

        $this->assertStringContainsString('[TRUNCATED]', (string) json_encode(new Sanitizer()->sanitize($deep)));
    }
}
