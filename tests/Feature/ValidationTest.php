<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Tests\Feature;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RoayaPay\PlatformCore\Tests\TestCase;
use RoayaPay\PlatformCore\Validation\ApiValidator;
use RoayaPay\PlatformCore\Validation\FieldNameResolver;
use stdClass;

final class ValidationTest extends TestCase
{
    #[Test]
    public function arabic_validation_messages_match_the_contract_example(): void
    {
        $response = $this->postJson('/api/register', ['password' => 'short'], $this->ar());

        $response->assertOk();
        $body = $this->assertFourKeys($response);
        $this->assertFalse($body->status);
        $this->assertSame(2000, $body->code);
        $this->assertSame("البريد الإلكتروني مطلوب\nكلمة المرور يجب ألا تقل عن 8 أحرف", $body->message);
        $this->assertEquals(new stdClass(), $body->data);
    }

    #[Test]
    public function english_validation_messages_match_the_contract_example(): void
    {
        $body = $this->body($this->postJson('/api/register', ['password' => 'short'], $this->en()));

        $this->assertSame("Email is required\nPassword must be at least 8 characters", $body->message);
        $this->assertSame(2000, $body->code);
    }

    #[Test]
    public function only_one_message_per_field_by_default(): void
    {
        // "password" fails both string and min:8 → only the first message is returned.
        $body = $this->body($this->postJson('/api/register', ['email' => 'a@b.co', 'password' => ['x']], $this->en()));

        $this->assertSame('Password must be text', $body->message);
        $this->assertStringNotContainsString("\n", $body->message);
    }

    #[Test]
    public function all_messages_per_field_can_be_enabled(): void
    {
        config(['roayapay.validation.all_messages_per_field' => true]);
        $this->app->forgetInstance(\RoayaPay\PlatformCore\Validation\ValidationMessageResolver::class);

        $message = ApiValidator::message(new MessageBag(['password' => ['first', 'second']]));

        $this->assertSame("first\nsecond", $message);
    }

    #[Test]
    public function validation_details_are_never_placed_in_data(): void
    {
        $content = (string) $this->postJson('/api/register', [], $this->en())->getContent();

        $this->assertStringContainsString('"data":{}', $content);
        $this->assertStringNotContainsString('"errors"', $content);
    }

    #[Test]
    public function the_throwing_api_validates_and_renders_in_the_request_language(): void
    {
        $body = $this->body($this->postJson('/api/register-throw', ['email' => 'not-an-email', 'password' => 'longenough'], $this->ar()));

        $this->assertSame(2000, $body->code);
        $this->assertSame('البريد الإلكتروني يجب أن يكون بريدًا إلكترونيًا صحيحًا', $body->message);
    }

    #[Test]
    public function framework_validation_is_localized_on_api_requests_without_changing_the_locale(): void
    {
        $ar = $this->body($this->postJson('/api/framework-validate', ['email' => 'x@y.co', 'amount' => 5000], $this->ar()));
        $en = $this->body($this->postJson('/api/framework-validate', ['email' => 'x@y.co', 'amount' => 5000], $this->en()));

        $this->assertSame('المبلغ يجب أن يكون بين 1 و 1000', $ar->message);
        $this->assertSame('Amount must be between 1 and 1000', $en->message);
        $this->assertSame('en', App::getLocale());
    }

    #[Test]
    public function form_requests_are_normalized_and_localized(): void
    {
        $body = $this->body($this->postJson('/api/form-request', ['amount' => 0, 'currency' => 'BTC'], $this->ar()));

        $this->assertSame(2000, $body->code);
        $this->assertSame("المبلغ يجب ألا يقل عن 1\nقيمة العملة المختارة غير صحيحة", $body->message);

        $ok = $this->body($this->postJson('/api/form-request', ['amount' => 10, 'currency' => 'EGP'], $this->en()));
        $this->assertSame(1000, $ok->code);
    }

    #[Test]
    public function form_request_authorization_failure_is_forbidden(): void
    {
        $body = $this->body($this->postJson('/api/form-request', ['deny' => true], $this->en())->assertOk());

        $this->assertSame(3002, $body->code);
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, string, string}>
     */
    public static function rules(): array
    {
        return [
            'required' => [['email' => 'required'], [], 'Email is required', 'البريد الإلكتروني مطلوب'],
            'email' => [['email' => 'email'], ['email' => 'x'], 'Email must be a valid email address', 'البريد الإلكتروني يجب أن يكون بريدًا إلكترونيًا صحيحًا'],
            'min' => [['password' => 'string|min:8'], ['password' => 'abc'], 'Password must be at least 8 characters', 'كلمة المرور يجب ألا تقل عن 8 أحرف'],
            'max' => [['name' => 'string|max:3'], ['name' => 'abcdef'], 'Name must not be longer than 3 characters', 'الاسم يجب ألا يزيد عن 3 حرفًا'],
            'numeric' => [['amount' => 'numeric'], ['amount' => 'abc'], 'Amount must be a number', 'المبلغ يجب أن يكون رقمًا'],
            'integer' => [['quantity' => 'integer'], ['quantity' => 1.5], 'Quantity must be an integer', 'الكمية يجب أن يكون عددًا صحيحًا'],
            'string' => [['name' => 'string'], ['name' => 5], 'Name must be text', 'الاسم يجب أن يكون نصًا'],
            'confirmed' => [['password' => 'confirmed'], ['password' => 'a', 'password_confirmation' => 'b'], 'Password confirmation does not match', 'تأكيد كلمة المرور غير مطابق'],
            'same' => [['password' => 'same:email'], ['password' => 'a', 'email' => 'b'], 'Password and email must match', 'كلمة المرور و البريد الإلكتروني يجب أن يتطابقا'],
            'different' => [['password' => 'different:email'], ['password' => 'a', 'email' => 'a'], 'Password and email must be different', 'كلمة المرور و البريد الإلكتروني يجب أن يكونا مختلفين'],
            'digits' => [['pin' => 'digits:4'], ['pin' => '12'], 'PIN must be 4 digits', 'الرقم السري يجب أن يتكون من 4 أرقام'],
            'between' => [['amount' => 'numeric|between:1,10'], ['amount' => 50], 'Amount must be between 1 and 10', 'المبلغ يجب أن يكون بين 1 و 10'],
            'date' => [['date' => 'date'], ['date' => 'not-a-date'], 'Date must be a valid date', 'التاريخ يجب أن يكون تاريخًا صحيحًا'],
            'boolean' => [['is_active' => 'boolean'], ['is_active' => 'maybe'], 'Active status must be true or false', 'حالة التفعيل يجب أن تكون قيمته صح أو خطأ'],
            'in' => [['currency' => 'in:EGP,USD'], ['currency' => 'BTC'], 'The selected currency is invalid', 'قيمة العملة المختارة غير صحيحة'],
            'regex' => [['code' => 'regex:/^[A-Z]+$/'], ['code' => 'abc'], 'Code format is invalid', 'صيغة الرمز غير صحيحة'],
            'unknown field name' => [['merchant_reference_no' => 'required'], [], 'Merchant reference no is required', 'merchant reference no مطلوب'],
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $data
     */
    #[Test]
    #[DataProvider('rules')]
    public function common_rules_are_translated_in_both_languages(array $rules, array $data, string $english, string $arabic): void
    {
        $en = $this->body($this->postJson('/api/rules', [...$data, '_rules' => $rules], $this->en()));
        $ar = $this->body($this->postJson('/api/rules', [...$data, '_rules' => $rules], $this->ar()));

        $this->assertSame($english, $en->message);
        $this->assertSame($arabic, $ar->message);
    }

    #[Test]
    public function unique_and_exists_rules_use_the_database_and_are_translated(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table): void {
            $table->id();
            $table->string('email');
        });
        $this->app['db']->table('users')->insert(['email' => 'taken@roayapay.test']);

        $unique = $this->body($this->postJson('/api/rules', ['email' => 'taken@roayapay.test', '_rules' => ['email' => 'unique:users,email']], $this->ar()));
        $exists = $this->body($this->postJson('/api/rules', ['user_id' => 999, '_rules' => ['user_id' => 'exists:users,id']], $this->en()));

        $this->assertSame('البريد الإلكتروني مستخدم من قبل', $unique->message);
        $this->assertSame('The selected user is invalid', $exists->message);
    }

    #[Test]
    public function multiple_failures_are_joined_with_newlines_in_field_order(): void
    {
        $body = $this->body($this->postJson('/api/rules', [
            'amount' => 'x',
            'currency' => 'BTC',
            '_rules' => ['email' => 'required', 'amount' => 'numeric', 'currency' => 'in:EGP'],
        ], $this->en()));

        $this->assertSame(['Email is required', 'Amount must be a number', 'The selected currency is invalid'], explode("\n", $body->message));
    }

    #[Test]
    public function field_names_are_translated(): void
    {
        $resolver = $this->app->make(FieldNameResolver::class);

        $this->assertSame('البريد الإلكتروني', $resolver->resolve('email', 'ar'));
        $this->assertSame('email', $resolver->resolve('email', 'en'));
        $this->assertSame('المبلغ', $resolver->resolve('items.0.amount', 'ar'));
        $this->assertSame('merchant code', $resolver->resolve('merchantCode', 'en'));
    }

    #[Test]
    public function applications_can_extend_field_names_and_messages_through_lang_files(): void
    {
        $path = sys_get_temp_dir().'/roayapay-lang-'.uniqid();
        File::ensureDirectoryExists("$path/en");
        File::ensureDirectoryExists("$path/vendor/roayapay/ar");

        // App-level attribute name (takes precedence over package names).
        File::put("$path/en/validation.php", "<?php return ['attributes' => ['email' => 'e-mail address']];");
        // Published package override for an Arabic rule message + new field name.
        File::put("$path/vendor/roayapay/ar/validation.php", "<?php return ['required' => 'حقل :attribute إلزامي'];");
        File::put("$path/vendor/roayapay/ar/fields.php", "<?php return ['merchant_code' => 'كود التاجر'];");

        $this->app['translation.loader']->addPath($path);

        try {
            $en = $this->body($this->postJson('/api/rules', ['_rules' => ['email' => 'required']], $this->en()));
            $ar = $this->body($this->postJson('/api/rules', ['_rules' => ['merchant_code' => 'required']], $this->ar()));

            $this->assertSame('E-mail address is required', $en->message);
            $this->assertSame('حقل كود التاجر إلزامي', $ar->message);
        } finally {
            File::deleteDirectory($path);
        }
    }

    #[Test]
    public function custom_inline_messages_are_preserved(): void
    {
        $this->app['router']->middleware('roayapay.api')->post('api/inline', fn () => ApiValidator::getResponse(
            ApiValidator::make([], ['amount' => 'required'], ['amount.required' => 'Send an amount please']),
        ));

        $this->assertSame('Send an amount please', $this->body($this->postJson('/api/inline', [], $this->en()))->message);
    }

    #[Test]
    public function validation_outside_api_requests_keeps_laravel_defaults(): void
    {
        $validator = Validator::make([], ['email' => 'required']);
        $validator->fails();

        // Laravel's own English line, untouched by the package.
        $this->assertSame('The email field is required.', $validator->errors()->first('email'));
    }

    #[Test]
    public function the_shared_translator_is_not_mutated_by_api_validation(): void
    {
        $translator = $this->app['translator'];
        $localeBefore = $translator->getLocale();

        $this->postJson('/api/register', [], $this->ar());

        $this->assertSame($localeBefore, $translator->getLocale());
        $this->assertSame('The email field is required.', $translator->get('validation.required', ['attribute' => 'email']));
    }
}
