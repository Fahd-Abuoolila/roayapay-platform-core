<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RoayaPay\PlatformCore\Support\ResponseExemptions;
use RoayaPay\PlatformCore\Tests\TestCase;

final class EnforcementTest extends TestCase
{
    #[Test]
    public function an_approved_response_passes_through(): void
    {
        $this->getJson('/api/success', $this->en())->assertOk()->assertJsonPath('code', 1000);
        $this->assertStringNotContainsString('response_contract_violation', $this->allLogText());
    }

    #[Test]
    public function response_json_is_logged_and_replaced(): void
    {
        $response = $this->getJson('/api/raw-json', $this->en());

        $response->assertStatus(500);
        $body = $this->assertFourKeys($response);
        $this->assertSame(5005, $body->code);
        $this->assertFalse($body->status);
        $response->assertHeader('X-Request-Id');

        $this->assertStringContainsString('roayapay.response_contract_violation', $this->allLogText());
    }

    #[Test]
    public function a_raw_array_is_replaced(): void
    {
        $this->getJson('/api/raw-array', $this->ar())->assertStatus(500)->assertJsonPath('code', 5005);
    }

    #[Test]
    public function log_mode_keeps_the_original_response(): void
    {
        config(['roayapay.enforcement.mode' => 'log']);

        $this->getJson('/api/raw-json', $this->en())->assertOk()->assertExactJson(['a' => 1]);
        $this->assertStringContainsString('roayapay.response_contract_violation', $this->allLogText());
    }

    #[Test]
    public function downloads_streams_and_text_are_untouched(): void
    {
        $download = $this->get('/api/download', $this->en());
        $download->assertOk();
        $this->assertSame('file-content', $download->streamedContent());

        $this->get('/api/text', $this->en())->assertOk()->assertSee('plain text');
        $this->assertStringNotContainsString('response_contract_violation', $this->allLogText());
    }

    #[Test]
    public function an_attribute_exempts_a_controller_action(): void
    {
        $this->getJson('/api/exempt', $this->en())->assertOk()->assertExactJson(['received' => true]);
        $this->getJson('/api/not-exempt', $this->en())->assertStatus(500)->assertJsonPath('code', 5005);
    }

    #[Test]
    public function attribute_lookups_are_memoized(): void
    {
        $exemptions = $this->app->make(ResponseExemptions::class);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/exempt', $this->en());
            $this->getJson('/api/not-exempt', $this->en());
        }

        $this->assertSame(2, $exemptions->cachedCount());
    }

    #[Test]
    public function routes_can_be_allowed_by_name(): void
    {
        config(['roayapay.enforcement.allowed_routes' => ['legacy.json']]);
        $this->app->forgetInstance(ResponseExemptions::class);
        $this->app['router']->middleware('roayapay.api')->get('api/legacy', fn () => response()->json(['legacy' => true]))->name('legacy.json');
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->getJson('/api/legacy', $this->en())->assertOk()->assertExactJson(['legacy' => true]);
    }

    #[Test]
    public function web_routes_are_not_affected(): void
    {
        $this->getJson('/web/json')->assertOk()->assertExactJson(['ok' => true]);
        $this->get('/web/page')->assertOk()->assertSee('Hello', false);
        $this->assertStringNotContainsString('response_contract_violation', $this->allLogText());
    }
}
