<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RoayaPay\PlatformCore\Codes\ApiCodeRegistry;
use RoayaPay\PlatformCore\Codes\InvalidApiCodeRegistryException;
use RoayaPay\PlatformCore\Codes\RegistryValidator;
use RoayaPay\PlatformCore\Codes\UnknownApiCodeException;

final class ApiCodeRegistryTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return require __DIR__.'/../../config/roayapay_api_codes.php';
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return list<string>
     */
    private function errorsFor(array $overrides): array
    {
        return new RegistryValidator()->validate([...$this->defaults(), ...$overrides], ['ar', 'en']);
    }

    #[Test]
    public function the_shipped_registry_is_valid(): void
    {
        $registry = new ApiCodeRegistry($this->defaults());

        $this->assertSame(1000, $registry->get('SUCCESS')->code);
        $this->assertSame(500, $registry->get('CODE_ERR')->http);
        $this->assertSame('INSUFFICIENT_BALANCE', $registry->findByInteger(4001)?->key);
        $this->assertNull($registry->get('PAYMENT_STATUS_UNKNOWN')->processCompleted);
    }

    #[Test]
    public function the_contract_examples_are_registered_as_specified(): void
    {
        $registry = new ApiCodeRegistry($this->defaults());

        foreach ([
            'SUCCESS' => [1000, 200, true],
            'VALIDATION_ERROR' => [2000, 200, false],
            'UNAUTHORIZED' => [3001, 200, false],
            'INSUFFICIENT_BALANCE' => [4001, 200, false],
            'CODE_ERR' => [5000, 500, false],
        ] as $key => [$code, $http, $status]) {
            $definition = $registry->get($key);
            $this->assertSame([$code, $http, $status], [$definition->code, $definition->http, $definition->status], $key);
        }
    }

    #[Test]
    public function only_http_200_and_500_are_used(): void
    {
        foreach (new ApiCodeRegistry($this->defaults())->all() as $definition) {
            $this->assertContains($definition->http, [200, 500], $definition->key);
        }
    }

    #[Test]
    public function duplicate_integer_codes_are_detected(): void
    {
        $errors = $this->errorsFor(['DUPLICATE' => ['code' => 1000, 'http' => 200, 'status' => true, 'message' => ['ar' => 'x', 'en' => 'x']]]);

        $this->assertStringContainsString('Duplicate integer code 1000', implode("\n", $errors));
    }

    #[Test]
    public function missing_arabic_or_english_messages_are_detected(): void
    {
        $errors = implode("\n", $this->errorsFor([
            'NO_AR' => ['code' => 9001, 'http' => 200, 'status' => false, 'message' => ['en' => 'x']],
            'EMPTY_EN' => ['code' => 9002, 'http' => 200, 'status' => false, 'message' => ['ar' => 'x', 'en' => ' ']],
        ]));

        $this->assertStringContainsString('[NO_AR] is missing a non-empty "ar" message', $errors);
        $this->assertStringContainsString('[EMPTY_EN] is missing a non-empty "en" message', $errors);
    }

    #[Test]
    public function invalid_http_statuses_are_detected(): void
    {
        $errors = implode("\n", $this->errorsFor([
            'NOT_FOUND_404' => ['code' => 9003, 'http' => 404, 'status' => false, 'message' => ['ar' => 'x', 'en' => 'x']],
            'SUCCESS_500' => ['code' => 9004, 'http' => 500, 'status' => true, 'message' => ['ar' => 'x', 'en' => 'x']],
        ]));

        $this->assertStringContainsString('invalid HTTP status 404', $errors);
        $this->assertStringContainsString('[SUCCESS_500] cannot have status=true with HTTP 500', $errors);
    }

    #[Test]
    public function invalid_types_are_detected(): void
    {
        $errors = implode("\n", $this->errorsFor([
            'BAD_TYPES' => ['code' => '9005', 'http' => '200', 'status' => 'false', 'process_completed' => 'yes', 'message' => 'text'],
        ]));

        $this->assertStringContainsString('"code" must be a positive integer', $errors);
        $this->assertStringContainsString('"http" must be an integer', $errors);
        $this->assertStringContainsString('"status" must be a boolean', $errors);
        $this->assertStringContainsString('"process_completed" must be true, false or null', $errors);
        $this->assertStringContainsString('"message" must be an array', $errors);
    }

    #[Test]
    public function missing_fields_and_unknown_fields_are_detected(): void
    {
        $errors = implode("\n", $this->errorsFor([
            'INCOMPLETE' => ['code' => 9006],
            'EXTRA' => ['code' => 9007, 'http' => 200, 'status' => true, 'message' => ['ar' => 'x', 'en' => 'x'], 'colour' => 'red'],
        ]));

        $this->assertStringContainsString('[INCOMPLETE] is missing required field "http"', $errors);
        $this->assertStringContainsString('[INCOMPLETE] is missing required field "message"', $errors);
        $this->assertStringContainsString('[EXTRA] has unknown field(s): colour', $errors);
    }

    #[Test]
    public function invalid_symbolic_names_are_detected(): void
    {
        $errors = implode("\n", $this->errorsFor([
            'lowercase' => ['code' => 9008, 'http' => 200, 'status' => true, 'message' => ['ar' => 'x', 'en' => 'x']],
            'TRAILING_' => ['code' => 9009, 'http' => 200, 'status' => true, 'message' => ['ar' => 'x', 'en' => 'x']],
        ]));

        $this->assertStringContainsString('Invalid symbolic name [lowercase]', $errors);
        $this->assertStringContainsString('Invalid symbolic name [TRAILING_]', $errors);
    }

    #[Test]
    public function package_required_codes_cannot_be_removed(): void
    {
        $config = $this->defaults();
        unset($config['CODE_ERR']);

        $this->assertStringContainsString('Required package code [CODE_ERR] is missing', implode("\n", new RegistryValidator()->validate($config, ['ar', 'en'])));
    }

    #[Test]
    public function an_invalid_registry_cannot_be_constructed(): void
    {
        $this->expectException(InvalidApiCodeRegistryException::class);

        new ApiCodeRegistry([...$this->defaults(), 'BROKEN' => ['code' => 1000]]);
    }

    #[Test]
    public function unknown_codes_fail_clearly(): void
    {
        $registry = new ApiCodeRegistry($this->defaults());

        $this->assertFalse($registry->has('NOPE'));
        $this->assertNull($registry->find('NOPE'));

        $this->expectException(UnknownApiCodeException::class);
        $registry->get('NOPE');
    }

    #[Test]
    public function additional_languages_require_messages(): void
    {
        $errors = new RegistryValidator()->validate($this->defaults(), ['ar', 'en', 'fr']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('"fr" message', $errors[0]);
    }
}
