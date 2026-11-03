<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Testing;

use Illuminate\Testing\TestResponse;
use RoayaPay\PlatformCore\Analysis\ResponseContractScanner;
use RoayaPay\PlatformCore\Analysis\Violation;
use RoayaPay\PlatformCore\Codes\ApiCodeRegistry;

/**
 * PHPUnit helpers for applications using RoayaPay Platform Core.
 *
 *     use AssertsRoayaPayStandards;
 *
 *     public function test_api_code_follows_the_standard(): void
 *     {
 *         $this->assertApiCodeFollowsRoayaPayStandard([app_path('Http/Controllers/Api')]);
 *     }
 *
 *     $this->assertRoayaPayResponse($this->getJson('/api/x', ['Accept-Language' => 'en']), 'SUCCESS');
 */
trait AssertsRoayaPayStandards
{
    /**
     * @param  list<string>  $paths
     */
    public function assertApiCodeFollowsRoayaPayStandard(array $paths): void
    {
        $registry = $this->app->make(ApiCodeRegistry::class);
        $violations = new ResponseContractScanner(array_keys($registry->all()))->scan($paths);

        $this->assertSame([], array_map(fn (Violation $v): string => (string) $v, $violations), 'RoayaPay response standard violations found.');
    }

    public function assertRoayaPayResponse(TestResponse $response, string $code): void
    {
        $definition = $this->app->make(ApiCodeRegistry::class)->get($code);
        $json = json_decode((string) $response->getContent(), false);

        $this->assertIsObject($json, 'Response body is not a JSON object.');
        $this->assertSame(['status', 'code', 'message', 'data'], array_keys(get_object_vars($json)), 'Response must have exactly status, code, message, data.');
        $this->assertIsBool($json->status);
        $this->assertSame($definition->code, $json->code);
        $this->assertIsString($json->message);
        $this->assertTrue(is_object($json->data) || is_array($json->data), '"data" must be an object or array.');
        $response->assertStatus($definition->http);
    }
}
