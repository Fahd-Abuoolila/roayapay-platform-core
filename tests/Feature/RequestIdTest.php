<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Feature;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RoayaPay\PlatformCore\Tests\TestCase;

final class RequestIdTest extends TestCase
{
    #[Test]
    public function a_request_id_is_generated_and_returned_as_a_header_only(): void
    {
        $response = $this->getJson('/api/context', $this->en());

        $id = (string) $response->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUuid($id));
        $this->assertSame($id, $this->body($response)->data->request_id);
        $this->assertSame(['status', 'code', 'message', 'data'], array_keys((array) $this->body($response)));
    }

    #[Test]
    public function a_valid_incoming_request_id_is_propagated(): void
    {
        $response = $this->getJson('/api/context', [...$this->en(), 'X-Request-Id' => 'gw:2026-11-03:abcdef']);

        $response->assertHeader('X-Request-Id', 'gw:2026-11-03:abcdef');
    }

    #[Test]
    public function an_invalid_incoming_request_id_is_replaced(): void
    {
        foreach (['short', '<script>alert(1)</script>', str_repeat('a', 300), "abc\ndef12345"] as $bad) {
            $id = (string) $this->getJson('/api/context', [...$this->en(), 'X-Request-Id' => $bad])->headers->get('X-Request-Id');

            $this->assertNotSame($bad, $id);
            $this->assertTrue(Str::isUuid($id));
        }
    }

    #[Test]
    public function incoming_ids_can_be_distrusted(): void
    {
        config(['roayapay.request_id.trust_incoming' => false]);

        $id = $this->getJson('/api/context', [...$this->en(), 'X-Request-Id' => 'gateway-req-0001'])->headers->get('X-Request-Id');

        $this->assertNotSame('gateway-req-0001', $id);
    }

    #[Test]
    public function each_request_gets_its_own_id(): void
    {
        $a = $this->getJson('/api/context', $this->en())->headers->get('X-Request-Id');
        $b = $this->getJson('/api/context', $this->en())->headers->get('X-Request-Id');

        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function rejected_language_responses_still_get_a_request_id(): void
    {
        $this->getJson('/api/success', ['Accept-Language' => 'fr'])->assertHeader('X-Request-Id');
    }

    #[Test]
    public function errors_raised_before_route_middleware_still_get_a_request_id(): void
    {
        $id = (string) $this->getJson('/api/route-that-does-not-exist', $this->en())->assertJsonPath('code', 4005)->headers->get('X-Request-Id');

        $this->assertTrue(Str::isUuid($id));
    }

    #[Test]
    public function the_request_id_is_added_to_laravel_log_context(): void
    {
        $this->app['router']->middleware('roayapay.api')->get('api/plain-log', function () {
            \Illuminate\Support\Facades\Log::info('plain laravel log line');

            return \RoayaPay\PlatformCore\Response\BuildResponse::setCode('SUCCESS')->getResponse();
        });

        $this->getJson('/api/plain-log', [...$this->en(), 'X-Request-Id' => 'req-plain-0001']);

        $record = collect($this->logRecords())->first(fn ($r) => $r->message === 'plain laravel log line');
        $this->assertSame('req-plain-0001', $record->extra['request_id'] ?? $record->context['request_id'] ?? null);
    }
}
