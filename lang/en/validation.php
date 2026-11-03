<?php

declare(strict_types=1);

/*
 | RoayaPay validation messages (English).
 | Override by publishing: php artisan vendor:publish --tag=roayapay-lang
 | then edit lang/vendor/roayapay/en/validation.php.
 | Per-field overrides still go to your app's lang/en/validation.php "custom" key.
 */

return [
    'accepted' => ':Attribute must be accepted',
    'after' => ':Attribute must be a date after :date',
    'after_or_equal' => ':Attribute must be a date after or equal to :date',
    'alpha' => ':Attribute may only contain letters',
    'alpha_dash' => ':Attribute may only contain letters, numbers, dashes and underscores',
    'alpha_num' => ':Attribute may only contain letters and numbers',
    'array' => ':Attribute must be a list',
    'before' => ':Attribute must be a date before :date',
    'before_or_equal' => ':Attribute must be a date before or equal to :date',
    'between' => [
        'numeric' => ':Attribute must be between :min and :max',
        'string' => ':Attribute must be between :min and :max characters',
        'array' => ':Attribute must have between :min and :max items',
        'file' => ':Attribute must be between :min and :max kilobytes',
    ],
    'boolean' => ':Attribute must be true or false',
    'confirmed' => ':Attribute confirmation does not match',
    'date' => ':Attribute must be a valid date',
    'date_format' => ':Attribute must match the format :format',
    'decimal' => ':Attribute must have :decimal decimal places',
    'different' => ':Attribute and :other must be different',
    'digits' => ':Attribute must be :digits digits',
    'digits_between' => ':Attribute must be between :min and :max digits',
    'email' => ':Attribute must be a valid email address',
    'exists' => 'The selected :attribute is invalid',
    'file' => ':Attribute must be a file',
    'filled' => ':Attribute must have a value',
    'gt' => [
        'numeric' => ':Attribute must be greater than :value',
        'string' => ':Attribute must be longer than :value characters',
        'array' => ':Attribute must have more than :value items',
        'file' => ':Attribute must be greater than :value kilobytes',
    ],
    'gte' => [
        'numeric' => ':Attribute must be greater than or equal to :value',
        'string' => ':Attribute must be at least :value characters',
        'array' => ':Attribute must have :value items or more',
        'file' => ':Attribute must be at least :value kilobytes',
    ],
    'image' => ':Attribute must be an image',
    'in' => 'The selected :attribute is invalid',
    'integer' => ':Attribute must be an integer',
    'ip' => ':Attribute must be a valid IP address',
    'json' => ':Attribute must be a valid JSON string',
    'lt' => [
        'numeric' => ':Attribute must be less than :value',
        'string' => ':Attribute must be shorter than :value characters',
        'array' => ':Attribute must have fewer than :value items',
        'file' => ':Attribute must be less than :value kilobytes',
    ],
    'lte' => [
        'numeric' => ':Attribute must be less than or equal to :value',
        'string' => ':Attribute must not be longer than :value characters',
        'array' => ':Attribute must not have more than :value items',
        'file' => ':Attribute must not be greater than :value kilobytes',
    ],
    'max' => [
        'numeric' => ':Attribute must not be greater than :max',
        'string' => ':Attribute must not be longer than :max characters',
        'array' => ':Attribute must not have more than :max items',
        'file' => ':Attribute must not be greater than :max kilobytes',
    ],
    'max_digits' => ':Attribute must not have more than :max digits',
    'mimes' => ':Attribute must be a file of type: :values',
    'min' => [
        'numeric' => ':Attribute must be at least :min',
        'string' => ':Attribute must be at least :min characters',
        'array' => ':Attribute must have at least :min items',
        'file' => ':Attribute must be at least :min kilobytes',
    ],
    'min_digits' => ':Attribute must have at least :min digits',
    'not_in' => 'The selected :attribute is invalid',
    'numeric' => ':Attribute must be a number',
    'present' => ':Attribute must be present',
    'prohibited' => ':Attribute is not allowed',
    'regex' => ':Attribute format is invalid',
    'required' => ':Attribute is required',
    'required_if' => ':Attribute is required when :other is :value',
    'required_unless' => ':Attribute is required unless :other is in :values',
    'required_with' => ':Attribute is required when :values is present',
    'required_with_all' => ':Attribute is required when :values are present',
    'required_without' => ':Attribute is required when :values is not present',
    'required_without_all' => ':Attribute is required when none of :values are present',
    'same' => ':Attribute and :other must match',
    'size' => [
        'numeric' => ':Attribute must be :size',
        'string' => ':Attribute must be :size characters',
        'array' => ':Attribute must contain :size items',
        'file' => ':Attribute must be :size kilobytes',
    ],
    'string' => ':Attribute must be text',
    'timezone' => ':Attribute must be a valid timezone',
    'unique' => ':Attribute has already been taken',
    'uploaded' => ':Attribute failed to upload',
    'url' => ':Attribute must be a valid URL',
    'uuid' => ':Attribute must be a valid UUID',
];
