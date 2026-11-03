<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Feature;

use Monolog\Level;
use PHPUnit\Framework\Attributes\Test;
use RoayaPay\PlatformCore\Tests\Fixtures\TestRoutes;
use RoayaPay\PlatformCore\Tests\TestCase;

final class ExceptionRenderingTest extends TestCase
{
    #[Test]
    public function a_business_exception_is_a_known_result_with_http_200(): void
    {
        $response = $this->getJson('/api/balance', $this->ar());

        $response->assertStatus(200);
        $body = $this->assertFourKeys($response);
        $this->assertFalse($body->status);
        $this->assertSame(4001, $body->code);
        $this->assertSame('الرصيد غير كافٍ', $body->message);
        $this->assertEquals((object) ['available' => '10.00'], $body->data);
    }

    #[Test]
    public function the_legacy_constructor_form_works(): void
    {
        $this->getJson('/api/legacy-constructor', $this->en())->assertOk()->assertJsonPath('code', 4001)->assertJsonPath('message', 'Insufficient balance');
    }

    #[Test]
    public function a_bilingual_override_on_the_exception_is_rendered(): void
    {
        $this->getJson('/api/balance-message', $this->ar())->assertJsonPath('message', 'رسالة مخصصة');
        $this->getJson('/api/balance-message', $this->en())->assertJsonPath('message', 'Custom message');
    }

    #[Test]
    public function expected_business_outcomes_are_not_reported(): void
    {
        $this->getJson('/api/balance', $this->en());
        $this->getJson('/api/merchant-missing', $this->en())->assertOk()->assertJsonPath('code', 4010);

        $this->assertSame([], $this->exceptionLogs());
        $this->assertStringNotContainsString('never-logged', $this->allLogText());
    }

    #[Test]
    public function a_validation_exception_from_the_framework_is_rendered_in_the_request_language(): void
    {
        $body = $this->body($this->postJson('/api/register-throw', [], $this->ar())->assertOk());

        $this->assertSame(2000, $body->code);
        $this->assertSame("البريد الإلكتروني مطلوب\nكلمة المرور مطلوب", $body->message);
        $this->assertEquals(new \stdClass(), $body->data);
    }

    #[Test]
    public function an_unexpected_exception_is_http_500_code_err_without_internal_details(): void
    {
        $response = $this->getJson('/api/boom', $this->en());

        $response->assertStatus(500);
        $body = $this->assertFourKeys($response);
        $this->assertFalse($body->status);
        $this->assertSame(5000, $body->code);
        $this->assertSame('An unexpected error occurred. Please try again.', $body->message);
        $this->assertEquals(new \stdClass(), $body->data);

        $content = (string) $response->getContent();
        foreach (['SQLSTATE', 'hunter2', 'Sup3rS3cret', '/var/www', 'Secret.php', 'RuntimeException', 'trace', 'file', 'line', 'exception'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $content, "Response leaked [$leak] even with APP_DEBUG=true");
        }
    }

    #[Test]
    public function an_unexpected_exception_is_reported_once_with_sanitized_context(): void
    {
        $this->getJson('/api/boom', $this->en());

        $logs = $this->exceptionLogs();
        $this->assertCount(1, $logs, 'Report and render paths must not log the same exception twice');

        $error = $logs[0]->context['error'];
        $this->assertSame(Level::Error, $logs[0]->level);
        $this->assertSame(\RuntimeException::class, $error['exception']);
        $this->assertSame('CODE_ERR', $error['api_code']);
        $this->assertSame('INTERNAL', $error['category']);
        $this->assertSame('GET', $error['method']);
        $this->assertSame('/api/boom', $error['path']);
        $this->assertNotEmpty($error['request_id']);
        $this->assertStringEndsWith('TestRoutes.php', $error['file']);
        $this->assertIsInt($error['line']);
        $this->assertNotEmpty($error['timestamp']);

        $text = $this->allLogText();
        $this->assertStringNotContainsString('hunter2', $text);
        $this->assertStringNotContainsString('Sup3rS3cret', $text);
    }

    #[Test]
    public function php_errors_are_reported_as_critical(): void
    {
        $this->getJson('/api/type-error', $this->en())->assertStatus(500)->assertJsonPath('code', 5000);

        $this->assertSame(Level::Critical, $this->exceptionLogs()[0]->level);
    }

    #[Test]
    public function database_failures_are_500_and_never_expose_sql_or_bindings(): void
    {
        $response = $this->getJson('/api/query', $this->en());

        $response->assertStatus(500)->assertJsonPath('code', 5000);
        $this->assertStringNotContainsString('cards', (string) $response->getContent());

        $log = $this->exceptionLogs()[0];
        $this->assertSame(Level::Critical, $log->level);
        $this->assertSame('DATABASE', $log->context['error']['category']);
        $this->assertStringNotContainsString('4111111111111111', $this->allLogText());
    }

    #[Test]
    public function model_not_found_is_a_known_not_found_result(): void
    {
        $this->getJson('/api/model', $this->ar())->assertOk()->assertJsonPath('code', 4004)->assertJsonPath('message', 'العنصر المطلوب غير موجود');
    }

    #[Test]
    public function authentication_and_authorization_outcomes_are_known_results(): void
    {
        $this->getJson('/api/auth', $this->en())->assertOk()->assertJsonPath('code', 3001)->assertJsonPath('status', false);
        $this->getJson('/api/authz', $this->en())->assertOk()->assertJsonPath('code', 3002);
        $this->getJson('/api/forbidden', $this->en())->assertOk()->assertJsonPath('code', 3002);
    }

    #[Test]
    public function authorization_denials_go_to_the_security_log(): void
    {
        $this->getJson('/api/authz', $this->en());

        $logs = $this->exceptionLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('SECURITY', $logs[0]->context['roayapay']['level']);
        $this->assertSame('security', $logs[0]->context['error']['reporting']);
    }

    #[Test]
    public function unknown_api_routes_and_methods_use_the_standard_format(): void
    {
        $this->getJson('/api/does-not-exist', $this->ar())->assertOk()
            ->assertJsonPath('code', 4005)
            ->assertJsonPath('message', 'المسار المطلوب غير موجود');

        $this->getJson('/api/only-post', $this->en())->assertOk()->assertJsonPath('code', 4006);
        $this->getJson('/api/abort-418', $this->en())->assertOk()->assertJsonPath('code', 2004);
    }

    #[Test]
    public function an_unregistered_code_fails_safely_and_loudly(): void
    {
        $this->getJson('/api/unknown-code', $this->en())->assertStatus(500)->assertJsonPath('code', 5000);

        $log = $this->exceptionLogs()[0];
        $this->assertSame(Level::Critical, $log->level);
        $this->assertSame('unregistered_api_code', $log->context['error']['context']['reason']);
        $this->assertSame('NOT_A_REGISTERED_CODE', $log->context['error']['context']['api_code']);
    }

    #[Test]
    public function timeouts_are_reported_as_unknown_outcome_not_as_failed_payments(): void
    {
        $body = $this->body($this->getJson('/api/timeout', $this->en())->assertStatus(500));

        $this->assertSame(5007, $body->code);
        $this->assertStringContainsString('verify', $body->message);

        $this->getJson('/api/refused', $this->en())->assertStatus(500)->assertJsonPath('code', 5006);
        $this->getJson('/api/provider-down', $this->en())->assertStatus(500)->assertJsonPath('code', 5001);
    }

    #[Test]
    public function web_routes_keep_laravels_default_exception_handling(): void
    {
        $response = $this->get('/web/boom', ['Accept' => 'text/html']);

        $response->assertStatus(500);
        $this->assertStringNotContainsString('"code":5000', (string) $response->getContent());
        $this->assertStringContainsString('web failure', (string) $response->getContent()); // Laravel debug page
    }

    #[Test]
    public function web_json_requests_are_not_converted(): void
    {
        $this->getJson('/web/boom')->assertStatus(500)->assertJsonMissingPath('code');
    }

    #[Test]
    public function exception_responses_carry_the_request_id_header(): void
    {
        $response = $this->getJson('/api/balance', [...$this->en(), 'X-Request-Id' => 'gateway-req-0001']);

        $response->assertHeader('X-Request-Id', 'gateway-req-0001');
    }

    #[Test]
    public function internal_diagnostic_context_never_reaches_the_response(): void
    {
        $content = (string) $this->getJson('/api/balance', $this->en())->getContent();

        $this->assertStringNotContainsString('never-logged', $content);
        $this->assertStringNotContainsString('merchant_id', $content);
        $this->assertStringNotContainsString('BUSINESS', $content);
    }

    #[Test]
    public function a_failure_while_rendering_still_produces_a_safe_code_err(): void
    {
        $this->app['router']->middleware('roayapay.api')->get('api/unencodable', function () {
            // INF cannot be JSON-encoded, so building the response itself throws.
            throw \RoayaPay\PlatformCore\Exceptions\RoayaPayException::business('INSUFFICIENT_BALANCE', ['ratio' => INF]);
        });

        $response = $this->getJson('/api/unencodable', $this->en());

        $response->assertStatus(500);
        $body = $this->assertFourKeys($response);
        $this->assertSame(5000, $body->code);
        $this->assertStringContainsString('render_failure', $this->allLogText());
    }

    #[Test]
    public function a_roayapay_response_thrown_via_http_response_exception_is_kept(): void
    {
        $this->app['router']->middleware('roayapay.api')->get('api/http-response', function () {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                \RoayaPay\PlatformCore\Response\BuildResponse::setCode('INSUFFICIENT_BALANCE')->getResponse(),
            );
        });
        $this->app['router']->middleware('roayapay.api')->get('api/http-response-raw', function () {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['raw' => true]));
        });

        $this->getJson('/api/http-response', $this->en())->assertOk()->assertJsonPath('code', 4001);
        $this->getJson('/api/http-response-raw', $this->en())->assertStatus(500)->assertJsonPath('code', 5005);
    }

    #[Test]
    public function the_secret_message_constant_really_contains_secrets(): void
    {
        // Guards the leak tests above against a fixture regression.
        $this->assertStringContainsString('hunter2', TestRoutes::SECRET_MESSAGE);
    }
}
