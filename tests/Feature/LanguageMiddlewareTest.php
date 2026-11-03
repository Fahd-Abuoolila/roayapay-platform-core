<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Feature;

use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use RoayaPay\PlatformCore\Tests\TestCase;

final class LanguageMiddlewareTest extends TestCase
{
    #[Test]
    public function an_arabic_request_is_answered_in_arabic(): void
    {
        $response = $this->getJson('/api/context', $this->ar());

        $response->assertOk()->assertHeader('Content-Language', 'ar');
        $this->assertSame('تم بنجاح', $this->body($response)->message);
        $this->assertSame('ar', $this->body($response)->data->language);
    }

    #[Test]
    public function an_english_request_is_answered_in_english(): void
    {
        $response = $this->getJson('/api/context', $this->en());

        $response->assertOk()->assertHeader('Content-Language', 'en');
        $this->assertSame('Done', $this->body($response)->message);
        $this->assertSame('en', $this->body($response)->data->language);
    }

    #[Test]
    public function the_header_is_case_and_whitespace_insensitive(): void
    {
        $this->assertSame('ar', $this->body($this->getJson('/api/context', ['Accept-Language' => ' AR ']))->data->language);
    }

    #[Test]
    public function a_missing_language_is_rejected_with_the_standard_format(): void
    {
        $response = $this->getJson('/api/success', ['Accept-Language' => '']);

        $response->assertOk();
        $body = $this->assertFourKeys($response);
        $this->assertFalse($body->status);
        $this->assertSame(2001, $body->code);
        $this->assertEquals(new \stdClass(), $body->data);
    }

    #[Test]
    public function a_request_without_the_header_never_reaches_the_controller(): void
    {
        // Symfony's test request adds "Accept-Language: en-us,en;q=0.5" by default; remove it entirely.
        $this->call('GET', '/api/success', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_ACCEPT_LANGUAGE' => null])
            ->assertOk()
            ->assertJsonPath('code', 2001)
            ->assertJsonPath('status', false);
    }

    #[Test]
    public function a_browser_style_language_list_is_rejected_as_unsupported(): void
    {
        $this->call('GET', '/api/success', server: ['HTTP_ACCEPT' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('code', 2002);
    }

    #[Test]
    public function an_unsupported_language_is_rejected(): void
    {
        foreach (['fr', 'ar-EG', 'ar,en;q=0.8', 'english'] as $value) {
            $body = $this->assertFourKeys($this->getJson('/api/success', ['Accept-Language' => $value])->assertOk());

            $this->assertSame(2002, $body->code, "Value [$value] should be unsupported");
            $this->assertFalse($body->status);
        }
    }

    #[Test]
    public function sequential_requests_do_not_leak_language_or_change_the_app_locale(): void
    {
        $initialLocale = App::getLocale();

        $sequence = ['ar', 'en', 'ar', 'ar', 'en'];
        foreach ($sequence as $language) {
            $body = $this->body($this->getJson('/api/context', ['Accept-Language' => $language]));

            $this->assertSame($language, $body->data->language);
            $this->assertSame($language === 'ar' ? 'تم بنجاح' : 'Done', $body->message);
            $this->assertSame($initialLocale, $body->data->app_locale, 'The global locale must never be mutated.');
        }

        // A following request WITHOUT a language must be rejected, not inherit the previous one.
        $this->getJson('/api/context', ['Accept-Language' => ''])->assertJsonPath('code', 2001);
        $this->assertSame($initialLocale, App::getLocale());
    }

    #[Test]
    public function it_adds_vary_accept_language(): void
    {
        $this->assertStringContainsString('Accept-Language', (string) $this->getJson('/api/success', $this->en())->headers->get('Vary'));
    }
}
