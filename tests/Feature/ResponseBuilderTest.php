<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Feature;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RoayaPay\PlatformCore\Codes\UnknownApiCodeException;
use RoayaPay\PlatformCore\Response\BuildResponse;
use RoayaPay\PlatformCore\Response\RoayaPayJsonResponse;
use RoayaPay\PlatformCore\Tests\TestCase;
use stdClass;

final class ResponseBuilderTest extends TestCase
{
    #[Test]
    public function it_returns_exactly_the_four_keys_with_correct_types(): void
    {
        $response = $this->getJson('/api/success', $this->en());

        $response->assertOk();
        $body = $this->assertFourKeys($response);
        $this->assertTrue($body->status);
        $this->assertSame(1000, $body->code);
        $this->assertSame('Done', $body->message);
        $this->assertEquals((object) ['id' => 7, 'amount' => '150.00'], $body->data);
    }

    #[Test]
    public function missing_data_is_an_empty_object_never_an_empty_array(): void
    {
        foreach (['/api/empty', '/api/empty-array'] as $uri) {
            $response = $this->getJson($uri, $this->en());

            $this->assertStringContainsString('"data":{}', (string) $response->getContent());
            $this->assertInstanceOf(stdClass::class, $this->body($response)->data);
        }
    }

    #[Test]
    public function the_default_message_is_resolved_in_the_request_language(): void
    {
        $this->assertSame('تم بنجاح', $this->body($this->getJson('/api/success', $this->ar()))->message);
        $this->assertSame('Done', $this->body($this->getJson('/api/success', $this->en()))->message);
    }

    #[Test]
    public function a_custom_bilingual_message_and_status_are_used(): void
    {
        $ar = $this->assertFourKeys($this->getJson('/api/custom-message', $this->ar())->assertOk());
        $en = $this->assertFourKeys($this->getJson('/api/custom-message', $this->en())->assertOk());

        $this->assertSame('الرصيد غير كافٍ للتحويل', $ar->message);
        $this->assertSame('Balance too low for transfer', $en->message);
        $this->assertSame(4001, $en->code);
        $this->assertFalse($en->status);
        $this->assertEquals((object) ['available' => '10.00'], $en->data);
    }

    #[Test]
    public function arabic_is_returned_unescaped(): void
    {
        $this->assertStringContainsString('تم بنجاح', (string) $this->getJson('/api/success', $this->ar())->getContent());
    }

    #[Test]
    public function a_missing_arabic_message_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"ar"');

        BuildResponse::setCode('SUCCESS')->setMessage(['en' => 'Only English']);
    }

    #[Test]
    public function a_missing_english_message_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"en"');

        BuildResponse::setCode('SUCCESS')->setMessage(['ar' => 'عربي فقط']);
    }

    #[Test]
    public function an_empty_message_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BuildResponse::setCode('SUCCESS')->setMessage(['ar' => '  ', 'en' => 'x']);
    }

    #[Test]
    public function an_unknown_code_fails_clearly(): void
    {
        $this->expectException(UnknownApiCodeException::class);
        $this->expectExceptionMessage('[DOES_NOT_EXIST] is not registered');

        BuildResponse::setCode('DOES_NOT_EXIST');
    }

    #[Test]
    public function default_and_custom_status_work(): void
    {
        $default = BuildResponse::setCode('INSUFFICIENT_BALANCE')->getResponse();
        $custom = BuildResponse::setCode('SUCCESS')->setStatus(false)->getResponse();

        $this->assertFalse($default->getData()->status);
        $this->assertFalse($custom->getData()->status);
        $this->assertTrue(BuildResponse::setCode('SUCCESS')->getResponse()->getData()->status);
    }

    #[Test]
    public function http_status_follows_the_code_definition(): void
    {
        $this->getJson('/api/success', $this->en())->assertStatus(200);
        $this->getJson('/api/custom-message', $this->en())->assertStatus(200);
        $this->getJson('/api/code-err', $this->en())->assertStatus(500);

        $this->assertSame(200, BuildResponse::setCode('VALIDATION_ERROR')->getResponse()->getStatusCode());
        $this->assertSame(200, BuildResponse::setCode('UNAUTHORIZED')->getResponse()->getStatusCode());
        $this->assertSame(500, BuildResponse::setCode('CODE_ERR')->getResponse()->getStatusCode());
    }

    #[Test]
    public function builders_are_independent_instances(): void
    {
        $first = BuildResponse::setCode('SUCCESS');
        $second = BuildResponse::setCode('SUCCESS');

        $this->assertNotSame($first, $second);

        $first->setData(['owner' => 'first'])->setStatus(false)->setMessage(['ar' => 'أ', 'en' => 'A']);
        $second->setData(['owner' => 'second']);

        $a = $first->getResponse()->getData(true);
        $b = $second->getResponse()->getData(true);

        $this->assertSame(['owner' => 'first'], $a['data']);
        $this->assertSame(['owner' => 'second'], $b['data']);
        $this->assertFalse($a['status']);
        $this->assertTrue($b['status']);
        $this->assertSame('Done', $b['message']);
    }

    #[Test]
    public function the_response_type_cannot_be_corrupted_after_creation(): void
    {
        $response = BuildResponse::setCode('SUCCESS')->getResponse();

        $this->assertInstanceOf(RoayaPayJsonResponse::class, $response);

        $this->expectException(InvalidArgumentException::class);
        $response->setData(['status' => true, 'code' => 1000, 'message' => 'Done', 'data' => [], 'extra' => 1]);
    }

    #[Test]
    public function arrayable_and_object_data_are_supported(): void
    {
        $response = BuildResponse::setCode('SUCCESS')->setData(collect(['a' => 1]))->getResponse();
        $this->assertSame(['a' => 1], $response->getData(true)['data']);

        $object = BuildResponse::setCode('SUCCESS')->setData((object) ['b' => 2])->getResponse();
        $this->assertSame(['b' => 2], $object->getData(true)['data']);

        $list = BuildResponse::setCode('SUCCESS')->setData([1, 2])->getResponse();
        $this->assertSame([1, 2], $list->getData(true)['data']);
    }
}
