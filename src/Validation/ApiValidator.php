<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Validation;

use Illuminate\Container\Container;
use Illuminate\Contracts\Support\MessageBag as MessageBagContract;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use RoayaPay\PlatformCore\Exceptions\RoayaPayException;
use RoayaPay\PlatformCore\Response\ResponseFactory;
use RoayaPay\PlatformCore\Response\RoayaPayJsonResponse;

/**
 * Public validation API for RoayaPay controllers.
 *
 *     $validator = ApiValidator::make($request->all(), ['email' => 'required|email']);
 *     if ($validator->fails()) {
 *         return ApiValidator::getResponse($validator->errors());
 *     }
 *
 *     // or simply:
 *     $data = ApiValidator::validate($request->all(), $rules); // throws RoayaPayException::validation()
 *
 * Messages are produced in the validated request language by a translator
 * bound to that language; the shared translator and app locale are untouched.
 */
final class ApiValidator
{
    /**
     * Create a validator whose messages use the current request language.
     *
     * Uses the framework validation factory, so custom rules, extensions and
     * presence verifiers (unique/exists) behave exactly as in Laravel.
     *
     * @param  array<mixed>  $data
     * @param  array<mixed>  $rules
     * @param  array<mixed>  $messages
     * @param  array<mixed>  $attributes
     */
    public static function make(array $data, array $rules, array $messages = [], array $attributes = []): Validator
    {
        $container = Container::getInstance();

        /** @var Validator $validator */
        $validator = $container->make(ValidationFactory::class)->make($data, $rules, $messages, $attributes);

        $language = $container->make(ResponseFactory::class)->language();
        $validator->setTranslator($container->make(LanguageTranslators::class)->for($language));

        return $validator;
    }

    /**
     * Validate and return the validated data, or throw RoayaPayException::validation().
     *
     * @param  array<mixed>  $data
     * @param  array<mixed>  $rules
     * @param  array<mixed>  $messages
     * @param  array<mixed>  $attributes
     * @return array<mixed>
     *
     * @throws RoayaPayException
     */
    public static function validate(array $data, array $rules, array $messages = [], array $attributes = []): array
    {
        $validator = self::make($data, $rules, $messages, $attributes);

        if ($validator->fails()) {
            throw RoayaPayException::validation($validator);
        }

        return $validator->validated();
    }

    /**
     * Build the standard VALIDATION_ERROR response: one localized string in
     * "message" (lines separated by "\n") and {} in "data".
     *
     * @param  MessageBagContract|ValidatorContract|ValidationException|array<string, string|list<string>>  $errors
     */
    public static function getResponse(MessageBagContract|ValidatorContract|ValidationException|array $errors): RoayaPayJsonResponse
    {
        $container = Container::getInstance();
        $factory = $container->make(ResponseFactory::class);

        return $factory->make(
            definition: $factory->registry()->get('VALIDATION_ERROR'),
            resolvedMessage: self::message($errors),
        );
    }

    /**
     * The formatted, localized validation message string.
     *
     * @param  MessageBagContract|ValidatorContract|ValidationException|array<string, string|list<string>>  $errors
     */
    public static function message(MessageBagContract|ValidatorContract|ValidationException|array $errors): string
    {
        $message = Container::getInstance()->make(ValidationMessageResolver::class)->format($errors);

        return $message !== ''
            ? $message
            : Container::getInstance()->make(ResponseFactory::class)->registry()->get('VALIDATION_ERROR')
                ->message(Container::getInstance()->make(ResponseFactory::class)->language());
    }
}
