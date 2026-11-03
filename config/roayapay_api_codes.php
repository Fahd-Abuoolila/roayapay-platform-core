<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RoayaPay API Code Registry
|--------------------------------------------------------------------------
|
| This is the single source of truth for every RoayaPay API result code.
| Application code refers to codes by their SYMBOLIC name only
| (e.g. BuildResponse::setCode('INSUFFICIENT_BALANCE')). Integer codes must
| never be hard-coded anywhere else.
|
| Each definition:
|   code               int        Unique RoayaPay integer code (> 0).
|   http               int        200 = processed with a known result,
|                                 500 = could not be processed (unexpected failure).
|   status             bool       Default "status" value in the response body.
|   process_completed  bool|null  true  = processing finished with a definitive result,
|                                 false = processing definitely did not complete,
|                                 null  = outcome unknown (e.g. provider timeout) – the
|                                         client must verify the transaction status.
|   message            array      Non-empty message for every supported language.
|
| Code ranges (convention):
|   1xxx success | 2xxx request/validation | 3xxx authentication/security
|   4xxx business, resource and payment results | 5xxx processing failures
|
| The package merges these defaults with your published copy, so the codes
| the package itself relies on (e.g. CODE_ERR, VALIDATION_ERROR) are always
| present. You may override their messages but must keep their semantics.
*/

return [

    // ---------------------------------------------------------------- 1xxx
    'SUCCESS' => [
        'code' => 1000,
        'http' => 200,
        'status' => true,
        'process_completed' => true,
        'message' => [
            'ar' => 'تم بنجاح',
            'en' => 'Done',
        ],
    ],

    // ---------------------------------------------------------------- 2xxx
    'VALIDATION_ERROR' => [
        'code' => 2000,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'بيانات الطلب غير صحيحة',
            'en' => 'The request data is invalid',
        ],
    ],

    'LANGUAGE_REQUIRED' => [
        'code' => 2001,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'يجب إرسال لغة الطلب في الترويسة Accept-Language (ar أو en)',
            'en' => 'The Accept-Language header is required (ar or en)',
        ],
    ],

    'LANGUAGE_NOT_SUPPORTED' => [
        'code' => 2002,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'لغة الطلب غير مدعومة، اللغات المدعومة: ar و en',
            'en' => 'The requested language is not supported. Supported languages: ar, en',
        ],
    ],

    'IDEMPOTENCY_KEY_REQUIRED' => [
        'code' => 2003,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'مفتاح عدم التكرار (Idempotency-Key) مطلوب أو غير صالح',
            'en' => 'A valid Idempotency-Key header is required',
        ],
    ],

    'BAD_REQUEST' => [
        'code' => 2004,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'الطلب غير صالح',
            'en' => 'The request is invalid',
        ],
    ],

    'REQUEST_TOO_LARGE' => [
        'code' => 2005,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'حجم الطلب أكبر من المسموح',
            'en' => 'The request is too large',
        ],
    ],

    // ---------------------------------------------------------------- 3xxx
    'UNAUTHORIZED' => [
        'code' => 3001,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'يجب تسجيل الدخول للمتابعة',
            'en' => 'Authentication is required',
        ],
    ],

    'FORBIDDEN' => [
        'code' => 3002,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'غير مسموح لك بتنفيذ هذا الإجراء',
            'en' => 'You are not allowed to perform this action',
        ],
    ],

    'SECURITY_VIOLATION' => [
        'code' => 3003,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'تم رفض الطلب لأسباب أمنية',
            'en' => 'The request was rejected for security reasons',
        ],
    ],

    'TOO_MANY_REQUESTS' => [
        'code' => 3004,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'عدد الطلبات كبير جدًا، برجاء المحاولة لاحقًا',
            'en' => 'Too many requests. Please try again later',
        ],
    ],

    // ---------------------------------------------------------------- 4xxx
    'BUSINESS_RULE_VIOLATION' => [
        'code' => 4000,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'لا يمكن تنفيذ العملية المطلوبة',
            'en' => 'The requested operation cannot be performed',
        ],
    ],

    'INSUFFICIENT_BALANCE' => [
        'code' => 4001,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'الرصيد غير كافٍ',
            'en' => 'Insufficient balance',
        ],
    ],

    'NOT_FOUND' => [
        'code' => 4004,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'العنصر المطلوب غير موجود',
            'en' => 'The requested resource was not found',
        ],
    ],

    'ROUTE_NOT_FOUND' => [
        'code' => 4005,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'المسار المطلوب غير موجود',
            'en' => 'The requested endpoint does not exist',
        ],
    ],

    'METHOD_NOT_ALLOWED' => [
        'code' => 4006,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'طريقة الطلب غير مسموحة لهذا المسار',
            'en' => 'The request method is not allowed for this endpoint',
        ],
    ],

    'MERCHANT_NOT_FOUND' => [
        'code' => 4010,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'التاجر غير موجود',
            'en' => 'Merchant not found',
        ],
    ],

    'IDEMPOTENCY_CONFLICT' => [
        'code' => 4090,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'تم استخدام مفتاح عدم التكرار مع بيانات مختلفة',
            'en' => 'The idempotency key was already used with a different request',
        ],
    ],

    'IDEMPOTENCY_IN_PROGRESS' => [
        'code' => 4091,
        'http' => 200,
        'status' => false,
        'process_completed' => false,
        'message' => [
            'ar' => 'الطلب الأصلي ما زال قيد التنفيذ، برجاء التحقق من حالته لاحقًا',
            'en' => 'The original request is still being processed. Please check its status later',
        ],
    ],

    'PAYMENT_DECLINED' => [
        'code' => 4100,
        'http' => 200,
        'status' => false,
        'process_completed' => true,
        'message' => [
            'ar' => 'تم رفض عملية الدفع',
            'en' => 'The payment was declined',
        ],
    ],

    // ---------------------------------------------------------------- 5xxx
    'CODE_ERR' => [
        'code' => 5000,
        'http' => 500,
        'status' => false,
        'process_completed' => false,
        'message' => [
            'ar' => 'حدث خطأ غير متوقع، برجاء المحاولة مرة أخرى',
            'en' => 'An unexpected error occurred. Please try again.',
        ],
    ],

    'PAYMENT_PROVIDER_UNAVAILABLE' => [
        'code' => 5001,
        'http' => 500,
        'status' => false,
        'process_completed' => false,
        'message' => [
            'ar' => 'مزود الدفع غير متاح حاليًا، برجاء المحاولة لاحقًا',
            'en' => 'The payment provider is currently unavailable. Please try again later',
        ],
    ],

    'PAYMENT_PROCESSING_FAILED' => [
        'code' => 5002,
        'http' => 500,
        'status' => false,
        'process_completed' => false,
        'message' => [
            'ar' => 'تعذرت معالجة عملية الدفع',
            'en' => 'The payment could not be processed',
        ],
    ],

    'SERVICE_UNAVAILABLE' => [
        'code' => 5003,
        'http' => 500,
        'status' => false,
        'process_completed' => false,
        'message' => [
            'ar' => 'الخدمة غير متاحة حاليًا، برجاء المحاولة لاحقًا',
            'en' => 'The service is temporarily unavailable. Please try again later',
        ],
    ],

    'PAYMENT_STATUS_UNKNOWN' => [
        'code' => 5004,
        'http' => 500,
        'status' => false,
        'process_completed' => null,
        'message' => [
            'ar' => 'تعذر تأكيد حالة عملية الدفع، برجاء التحقق من حالة العملية قبل إعادة المحاولة',
            'en' => 'The payment status could not be confirmed. Please verify the transaction status before retrying',
        ],
    ],

    'RESPONSE_CONTRACT_VIOLATION' => [
        'code' => 5005,
        'http' => 500,
        'status' => false,
        'process_completed' => null,
        'message' => [
            'ar' => 'تعذر إرجاع نتيجة الطلب بالشكل القياسي، برجاء التحقق من حالة العملية',
            'en' => 'The result could not be returned in the standard format. Please verify the operation status',
        ],
    ],

    'EXTERNAL_SERVICE_UNAVAILABLE' => [
        'code' => 5006,
        'http' => 500,
        'status' => false,
        'process_completed' => false,
        'message' => [
            'ar' => 'خدمة خارجية غير متاحة حاليًا، برجاء المحاولة لاحقًا',
            'en' => 'An external service is currently unavailable. Please try again later',
        ],
    ],

    'EXTERNAL_SERVICE_TIMEOUT' => [
        'code' => 5007,
        'http' => 500,
        'status' => false,
        'process_completed' => null,
        'message' => [
            'ar' => 'انتهت مهلة الاتصال بخدمة خارجية، برجاء التحقق من حالة العملية قبل إعادة المحاولة',
            'en' => 'An external service timed out. Please verify the operation status before retrying',
        ],
    ],

    'EXTERNAL_SERVICE_ERROR' => [
        'code' => 5008,
        'http' => 500,
        'status' => false,
        'process_completed' => null,
        'message' => [
            'ar' => 'حدث خطأ في خدمة خارجية، برجاء التحقق من حالة العملية قبل إعادة المحاولة',
            'en' => 'An external service failed. Please verify the operation status before retrying',
        ],
    ],

    'EXTERNAL_SERVICE_REJECTED' => [
        'code' => 5009,
        'http' => 500,
        'status' => false,
        'process_completed' => false,
        'message' => [
            'ar' => 'رفضت خدمة خارجية الطلب، برجاء المحاولة مرة أخرى',
            'en' => 'An external service rejected the request. Please try again',
        ],
    ],

    'IDEMPOTENCY_OUTCOME_UNKNOWN' => [
        'code' => 5010,
        'http' => 500,
        'status' => false,
        'process_completed' => null,
        'message' => [
            'ar' => 'نتيجة الطلب الأصلي غير مؤكدة، برجاء التحقق من حالة العملية قبل إعادة المحاولة',
            'en' => 'The outcome of the original request is unknown. Please verify the operation status before retrying',
        ],
    ],

];
