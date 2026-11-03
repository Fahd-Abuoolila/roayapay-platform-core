<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Architecture;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use RoayaPay\PlatformCore\Analysis\ResponseContractScanner;
use RoayaPay\PlatformCore\Analysis\Violation;
use RoayaPay\PlatformCore\Codes\ApiCodeRegistry;
use RoayaPay\PlatformCore\Testing\AssertsRoayaPayStandards;
use RoayaPay\PlatformCore\Tests\TestCase;

final class ResponseContractScannerTest extends TestCase
{
    use AssertsRoayaPayStandards;

    private function scanner(): ResponseContractScanner
    {
        return new ResponseContractScanner(array_keys($this->app->make(ApiCodeRegistry::class)->all()));
    }

    /**
     * @return list<Violation>
     */
    private function scanFixture(string $name): array
    {
        $file = __DIR__.'/../Fixtures/Scanner/'.$name.'.php.txt';

        return $this->scanner()->scanSource((string) file_get_contents($file), $name.'.php');
    }

    #[Test]
    public function it_detects_every_forbidden_pattern(): void
    {
        $violations = $this->scanFixture('BadPaymentController');
        $byRule = [];
        foreach ($violations as $violation) {
            $byRule[$violation->rule][] = $violation->line;
        }

        $this->assertSame([14, 19], $byRule[Violation::RESPONSE_JSON] ?? null, 'response()->json() and Response::json()');
        $this->assertSame([24, 29], $byRule[Violation::NEW_JSON_RESPONSE] ?? null, 'new JsonResponse (imported and FQCN)');
        $this->assertSame([34, 39, 54], $byRule[Violation::NUMERIC_CODE] ?? null, 'numeric codes in setCode, factories and arrays');
        $this->assertSame([44, 49], $byRule[Violation::UNKNOWN_CODE] ?? null, 'unregistered symbolic codes');
        $this->assertSame([59, 66], $byRule[Violation::RAW_ARRAY_RETURN] ?? null, 'raw array / compact returns');
    }

    #[Test]
    public function compliant_code_produces_no_violations(): void
    {
        $this->assertSame([], array_map('strval', $this->scanFixture('GoodPaymentController')));
    }

    #[Test]
    public function the_scanner_reads_directories(): void
    {
        $dir = sys_get_temp_dir().'/roayapay-scan-'.uniqid();
        mkdir($dir.'/Api', 0777, true);
        copy(__DIR__.'/../Fixtures/Scanner/BadPaymentController.php.txt', $dir.'/Api/BadPaymentController.php');
        copy(__DIR__.'/../Fixtures/Scanner/GoodPaymentController.php.txt', $dir.'/Api/GoodPaymentController.php');

        try {
            $violations = $this->scanner()->scan([$dir]);
            $this->assertCount(11, $violations);
            $this->assertSame([], array_filter($violations, fn (Violation $v) => str_contains($v->file, 'Good')));
        } finally {
            array_map('unlink', glob($dir.'/Api/*.php') ?: []);
            rmdir($dir.'/Api');
            rmdir($dir);
        }
    }

    #[Test]
    public function the_artisan_command_fails_ci_on_violations(): void
    {
        $dir = sys_get_temp_dir().'/roayapay-cmd-'.uniqid();
        mkdir($dir);
        copy(__DIR__.'/../Fixtures/Scanner/BadPaymentController.php.txt', $dir.'/BadPaymentController.php');

        try {
            $this->assertSame(1, Artisan::call('roayapay:scan', ['paths' => [$dir]]));
            $this->assertStringContainsString('11 violation(s)', Artisan::output());
        } finally {
            unlink($dir.'/BadPaymentController.php');
            rmdir($dir);
        }

        $this->assertSame(0, Artisan::call('roayapay:scan', ['paths' => [__DIR__.'/../../src']]));
    }

    #[Test]
    public function the_standalone_cli_works_without_laravel(): void
    {
        $dir = sys_get_temp_dir().'/roayapay-cli-'.uniqid();
        mkdir($dir);
        copy(__DIR__.'/../Fixtures/Scanner/BadPaymentController.php.txt', $dir.'/BadPaymentController.php');

        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../../bin/roayapay-scan').' '.escapeshellarg($dir).' --json', $output, $exit);
        } finally {
            unlink($dir.'/BadPaymentController.php');
            rmdir($dir);
        }

        $this->assertSame(1, $exit);
        $this->assertCount(11, json_decode(implode("\n", $output), true));
    }

    #[Test]
    public function the_package_test_routes_violations_are_caught_by_the_phpunit_helper(): void
    {
        // The good fixture passes the reusable assertion helper applications use in their own suites.
        $dir = sys_get_temp_dir().'/roayapay-helper-'.uniqid();
        mkdir($dir);
        copy(__DIR__.'/../Fixtures/Scanner/GoodPaymentController.php.txt', $dir.'/GoodPaymentController.php');

        try {
            $this->assertApiCodeFollowsRoayaPayStandard([$dir]);
        } finally {
            unlink($dir.'/GoodPaymentController.php');
            rmdir($dir);
        }

        $this->assertRoayaPayResponse($this->getJson('/api/balance', $this->en()), 'INSUFFICIENT_BALANCE');
    }

    #[Test]
    public function the_package_itself_follows_the_standard(): void
    {
        $this->assertSame([], array_map('strval', $this->scanner()->scan([__DIR__.'/../../src'])));
    }
}
