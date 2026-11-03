# RoayaPay Platform Core

`roayapay/platform-core` is the official RoayaPay engineering standard for Laravel APIs. It gives every RoayaPay project the same response contract, result codes, bilingual messages, validation output, exception API, error monitoring, logging, audit trail and idempotency foundations.

| | |
|---|---|
| Package | `roayapay/platform-core` |
| Version | `1.0.0` |
| PHP | **8.4** (`^8.4`) |
| Laravel | **12.x** (Illuminate components `^12.0`) |
| Tested with | PHP **8.4.27**, Laravel **12.70.2**, Orchestra Testbench Core 10.16.0, PHPUnit 11.5.57 |
| Namespace | `RoayaPay\PlatformCore\` |

> This is a dedicated Laravel 12 / PHP 8.4 package. It does not target Laravel 10, 11 or 13 and contains no compatibility layers for them.

---

## Contents

1. [Purpose](#1-purpose)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Configuration](#4-configuration)
5. [The API response contract](#5-the-api-response-contract)
6. [Response builder](#6-response-builder)
7. [Registering response codes](#7-registering-response-codes)
8. [Mandatory API language](#8-mandatory-api-language)
9. [Validation](#9-validation)
10. [Exceptions: one API for the whole application](#10-exceptions-one-api-for-the-whole-application)
11. [Error monitoring](#11-error-monitoring)
12. [Logging](#12-logging)
13. [Audit trail](#13-audit-trail)
14. [Transaction identity and idempotency](#14-transaction-identity-and-idempotency)
15. [Response enforcement (runtime)](#15-response-enforcement-runtime)
16. [Static analysis and CI](#16-static-analysis-and-ci)
17. [Laravel 12 integration](#17-laravel-12-integration)
18. [Octane / FrankenPHP](#18-octane--frankenphp)
19. [Extension points](#19-extension-points)
20. [Known limitations](#20-known-limitations)

---

## 1. Purpose

Every RoayaPay API answers with exactly four keys, uses RoayaPay-owned integer codes, speaks the language the client asked for, and follows one HTTP policy: **200 for every known result** (including business and validation failures) and **500 only when processing failed unexpectedly**.

The package makes that the default path of least resistance:

```php
if ($merchant->balance < $amount) {
    throw RoayaPayException::business('INSUFFICIENT_BALANCE');
}

return BuildResponse::setCode('SUCCESS')->setData($payment)->getResponse();
```

No manual JSON, no HTTP status codes, no translation calls, no try/catch in controllers, no manual logging.

## 2. Requirements

- PHP 8.4 with `mbstring`, `json`, `pdo` (for the database stores) and `tokenizer` (for the scanner).
- Laravel 12.x.
- Composer 2.

The package depends only on Illuminate components (`illuminate/support`, `http`, `routing`, `validation`, `translation`, `log`, `database`, `cache`, `console`, `contracts`), all `^12.0`. In a Laravel application these are provided by `laravel/framework`.

## 3. Installation

### From your package registry

```bash
composer require roayapay/platform-core
```

### Local development (Composer path repository)

Place the package next to your application (or anywhere on disk) and add a path repository to the **application's** `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "../roayapay-platform-core", "options": { "symlink": true } }
    ]
}
```

```bash
composer require roayapay/platform-core:@dev
```

### After installing

The service provider is auto-discovered. Then:

```bash
php artisan vendor:publish --tag=roayapay-config       # config/roayapay.php + config/roayapay_api_codes.php
php artisan vendor:publish --tag=roayapay-migrations   # audit + idempotency tables (if you use the database stores)
php artisan vendor:publish --tag=roayapay-lang         # optional: lang/vendor/roayapay/{ar,en}
php artisan migrate
php artisan roayapay:codes --validate
```

Then put your API routes behind the `roayapay.api` middleware group (see [§8](#8-mandatory-api-language)). The package never adds middleware to your routes by itself.

## 4. Configuration

| File | Purpose |
|---|---|
| `config/roayapay_api_codes.php` | The central code registry (the only place integer codes exist). |
| `config/roayapay.php` | API detection paths, languages, request ID, logging channels, sanitizer, monitoring reporters, exception mappings, validation, enforcement mode, audit and idempotency stores. |

Key options in `config/roayapay.php`:

```php
'api' => ['paths' => ['api/*']],                     // which paths get RoayaPay exception rendering
'language' => ['supported' => ['ar', 'en'], 'fallback' => 'en'],
'request_id' => ['header' => 'X-Request-Id', 'trust_incoming' => true],
'logging' => ['channel' => null, 'security_channel' => null],
'monitoring' => ['reporters' => [LogErrorReporter::class]],
'exceptions' => ['auto_register' => true, 'map' => [], 'mappers' => [], 'model_not_found' => []],
'validation' => ['localize_framework_validator' => true, 'all_messages_per_field' => false],
'enforcement' => ['mode' => 'replace', 'allowed_routes' => []],
'audit' => ['store' => 'database'],
'idempotency' => ['store' => 'database', 'lock_ttl' => 300, 'retention' => 86400],
```

`language.fallback` is used **only** to word responses that are produced before a language was validated (the "language required" rejection itself, or a 404 for an unknown API route). Valid requests never fall back silently.

## 5. The API response contract

```json
{ "status": true, "code": 1000, "message": "Done", "data": {} }
```

- `status` – boolean.
- `code` – RoayaPay integer code from the registry.
- `message` – string in the request language.
- `data` – object (or the explicitly supplied list). No data is always `{}`, never `[]`.
- Exactly these four keys, in this order. The request ID travels in the `X-Request-Id` **header**.

HTTP status policy:

| Situation | HTTP | Example code |
|---|---:|---|
| Success | 200 | `SUCCESS` 1000 |
| Validation failure | 200 | `VALIDATION_ERROR` 2000 |
| Not authenticated / not allowed | 200 | `UNAUTHORIZED` 3001 / `FORBIDDEN` 3002 |
| Business rejection | 200 | `INSUFFICIENT_BALANCE` 4001 |
| Unknown resource / endpoint | 200 | `NOT_FOUND` 4004 / `ROUTE_NOT_FOUND` 4005 |
| Unexpected failure | 500 | `CODE_ERR` 5000 |
| Provider timed out (outcome unknown) | 500 | `EXTERNAL_SERVICE_TIMEOUT` 5007 |

A known business result is **never** turned into `CODE_ERR`.

## 6. Response builder

```php
use RoayaPay\PlatformCore\Response\BuildResponse;

return BuildResponse::setCode('SUCCESS')
    ->setData($data)
    ->getResponse();

return BuildResponse::setCode('INSUFFICIENT_BALANCE')
    ->setData(['available' => '10.00'])
    ->setMessage([
        'ar' => 'الرصيد غير كافٍ',
        'en' => 'Insufficient balance',
    ])
    ->setStatus(false)
    ->getResponse();
```

| Method | Behaviour |
|---|---|
| `BuildResponse::setCode(string $code)` | Creates a **new** builder. Throws `UnknownApiCodeException` for an unregistered code. |
| `->setData(array\|object\|null)` | Optional. Accepts arrays, objects, `Arrayable`, `JsonSerializable`, `JsonResource`. Empty → `{}`. |
| `->setMessage(['ar' => ..., 'en' => ...])` | Optional. Both languages must be non-empty strings, otherwise `InvalidArgumentException`. |
| `->setStatus(bool)` | Optional override of the registered default status. |
| `->getResponse()` | Returns `RoayaPayJsonResponse` (extends `Illuminate\Http\JsonResponse`). HTTP status comes from the code definition. |

`RoayaPayJsonResponse::setData()` rejects any body that is not exactly the four keys, so the contract cannot be broken after the response is built. Arabic is emitted unescaped (`JSON_UNESCAPED_UNICODE`).

## 7. Registering response codes

Add codes to `config/roayapay_api_codes.php` (after publishing):

```php
'WALLET_FROZEN' => [
    'code' => 4200,
    'http' => 200,
    'status' => false,
    'process_completed' => true,
    'message' => [
        'ar' => 'المحفظة مجمدة',
        'en' => 'The wallet is frozen',
    ],
],
```

Rules (validated when the registry is built and by `php artisan roayapay:codes --validate`):

- Symbolic names are `UPPER_SNAKE_CASE`.
- Integer codes are positive and unique.
- `http` is `200` or `500` only; `status: true` with `500` is rejected.
- `status` is boolean; `process_completed` is `true`, `false` or `null` (null = outcome unknown, the client must verify).
- A non-empty message for every supported language.
- No unknown fields.
- The package's own codes (`SUCCESS`, `VALIDATION_ERROR`, `CODE_ERR`, …) are always merged in, so they can be re-worded but not removed.

Conventional ranges: 1xxx success · 2xxx request/validation · 3xxx authentication/security · 4xxx business/resource/payment results · 5xxx processing failures. Run `php artisan roayapay:codes` to list them.

## 8. Mandatory API language

Every API request must send `Accept-Language: ar` or `Accept-Language: en`.

- Missing → `LANGUAGE_REQUIRED` (2001). Unsupported (including browser lists like `ar,en;q=0.8`) → `LANGUAGE_NOT_SUPPORTED` (2002). Both are standard RoayaPay responses with HTTP 200.
- The validated language is stored in a request-scoped `RequestContext`. **The global app locale is never changed**, so nothing leaks between requests in Octane/FrankenPHP.
- Responses carry `Content-Language` and `Vary: Accept-Language`.

Register the middleware group for your API routes in `routes/api.php`:

```php
use Illuminate\Support\Facades\Route;

Route::middleware('roayapay.api')->group(function () {
    Route::post('payments', [PaymentController::class, 'store'])->middleware('roayapay.idempotency');
    Route::get('merchants/{merchant}', [MerchantController::class, 'show']);
});
```

`roayapay.api` = `AssignRequestId` → `EnforceRoayaPayResponse` → `RequireApiLanguage`. Individual aliases: `roayapay.request-id`, `roayapay.enforce`, `roayapay.language`, `roayapay.idempotency`.

If your API routes are not under `api/*`, set `roayapay.api.paths` so that errors raised before route middleware (e.g. unknown endpoints) are rendered in the RoayaPay format.

## 9. Validation

```php
use RoayaPay\PlatformCore\Validation\ApiValidator;

$validator = ApiValidator::make($request->all(), [
    'email' => 'required|email',
    'password' => 'required|string|min:8',
]);

if ($validator->fails()) {
    return ApiValidator::getResponse($validator->errors());
}
```

```json
{ "status": false, "code": 2000, "message": "البريد الإلكتروني مطلوب\nكلمة المرور يجب ألا تقل عن 8 أحرف", "data": {} }
{ "status": false, "code": 2000, "message": "Email is required\nPassword must be at least 8 characters", "data": {} }
```

Shorter, throwing form:

```php
$data = ApiValidator::validate($request->all(), $rules);   // throws RoayaPayException::validation()
```

`$request->validate()`, `Validator::make()` and **FormRequests** produce the same localized output on API requests (config `validation.localize_framework_validator`). A failed FormRequest authorization becomes `FORBIDDEN`.

| API | Description |
|---|---|
| `ApiValidator::make($data, $rules, $messages = [], $attributes = [])` | Laravel validator whose messages use the request language. Custom rules, extensions, `unique`/`exists` work as usual. |
| `ApiValidator::validate(...)` | Returns validated data or throws `RoayaPayException::validation()`. |
| `ApiValidator::getResponse(MessageBag\|Validator\|ValidationException\|array $errors)` | `VALIDATION_ERROR`, HTTP 200, one string in `message`, `{}` in `data`. |
| `ApiValidator::message(...)` | Just the formatted string. |

Behaviour:

- One message per field (the first failing rule) unless `validation.all_messages_per_field` is true; duplicates removed; joined with `"\n"`.
- Supported out of the box in both languages: `required, email, min, max, numeric, integer, string, unique, exists, confirmed, same, different, digits, between, date, boolean, in, regex` plus 38 more — 56 rules in total (see `lang/*/validation.php`). An unknown rule gets a generic message **in the same language**, never another language.
- Field names: your `lang/{ar,en}/validation.php` → `attributes` (highest priority), then `roayapay::fields` (shipped defaults, extend in `lang/vendor/roayapay/{lang}/fields.php`), then a humanized key.
- Override any package message by publishing `--tag=roayapay-lang` and editing `lang/vendor/roayapay/{lang}/validation.php`. Per-field messages in your app's `validation.custom` still apply.

**How it works (and why it is safe).** Laravel's `MessageBag` contains messages in whatever language its translator used. The package therefore never "re-translates" a bag. Instead, every API validator receives a `LanguageTranslator` permanently bound to the validated request language (one cached instance per language, sharing Laravel's file loader). The application translator and global locale are never modified. Web routes and console validation keep Laravel's default behaviour.

Inline messages you pass to the validator are used as given; if you need them bilingual, resolve them through the request language yourself (e.g. keep them in `lang/vendor/roayapay/{lang}/validation.php`).

## 10. Exceptions: one API for the whole application

`RoayaPayException` is the **only** exception class application code throws. Every factory returns this exact (final) class.

```php
use RoayaPay\PlatformCore\Exceptions\RoayaPayException;

throw RoayaPayException::business('INSUFFICIENT_BALANCE');
throw RoayaPayException::validation($validator);        // MessageBag | Validator | ValidationException | array
throw RoayaPayException::unauthorized();
throw RoayaPayException::forbidden();
throw RoayaPayException::notFound('MERCHANT_NOT_FOUND');
throw RoayaPayException::external('PAYMENT_PROVIDER_UNAVAILABLE');
throw RoayaPayException::internal('PAYMENT_PROCESSING_FAILED');
throw RoayaPayException::fromThrowable($exception);
throw new RoayaPayException('INSUFFICIENT_BALANCE');    // constructor form, BUSINESS category
```

### Factory reference

| Factory | Default code | Category | Default reporting | Severity |
|---|---|---|---|---|
| `business($code, $data, $message, $context, $previous)` | `BUSINESS_RULE_VIOLATION` | BUSINESS | not reported | info |
| `payment(...)` | `PAYMENT_DECLINED` | PAYMENT | reported | warning |
| `validation($errors, $data, $context, $previous)` | `VALIDATION_ERROR` | VALIDATION | not reported | info |
| `unauthorized($code, $message, $context, $previous)` | `UNAUTHORIZED` | AUTHENTICATION | not reported | info |
| `forbidden(...)` | `FORBIDDEN` | AUTHORIZATION | security log | warning |
| `notFound($code, $data, $message, $context, $previous)` | `NOT_FOUND` | RESOURCE | not reported | info |
| `external($code, $context, $previous, $retryable, $message)` | `EXTERNAL_SERVICE_UNAVAILABLE` | EXTERNAL_SERVICE | reported | error |
| `database($code, $context, $previous, $retryable)` | `CODE_ERR` | DATABASE | reported | critical |
| `security($code, $message, $context, $previous)` | `SECURITY_VIOLATION` | SECURITY | security log | warning |
| `internal($code, $context, $previous, $message)` | `CODE_ERR` | INTERNAL | reported | error |
| `system($code, $context, $previous)` | `CODE_ERR` | SYSTEM | reported | critical |
| `fromThrowable(Throwable $e)` | via normalizer | via normalizer | via normalizer | via normalizer |

Fluent modifiers (before throwing): `withData()`, `withMessage(['ar'=>..,'en'=>..])`, `withContext()`, `withSeverity(Severity::…)`, `withReporting(ReportingPolicy::…)`, `retryable(bool)`, `withTransactionId()`.

Readable properties (PHP 8.4 asymmetric visibility — readable everywhere, not writable from outside): `apiCode`, `category`, `data`, `messageOverride`, `context`, `severity`, `reporting`, `retryable`, `requestId`, `transactionId`, `validationErrors`.

**The registered code decides the response** (code, status, message, HTTP status). The category only supplies defaults for reporting, severity and retryability.

**What reaches the client:** the registered message (or your bilingual override) and the explicit `$data`. **What never does:** `$context`, the previous exception, its message, SQL, bindings, traces, file paths.

### Categories

| Category | Meaning |
|---|---|
| VALIDATION | Invalid client input. |
| AUTHENTICATION | Caller not authenticated. |
| AUTHORIZATION | Authenticated but not allowed. |
| RESOURCE | Resource or endpoint does not exist. |
| BUSINESS | A business rule rejected the operation. |
| PAYMENT | A payment-specific outcome such as a confirmed decline. |
| DATABASE | Database failure (query error, deadlock, lost connection). |
| EXTERNAL_SERVICE | A third-party service failed, timed out or rejected the call. |
| SECURITY | Security rejection (bad signature, CSRF, tampering, throttling). |
| INTERNAL | Unexpected failure in application logic. |
| SYSTEM | Environment/runtime failure (misconfiguration, unresolved services). |

### Framework and third-party exceptions (normalization)

Laravel, Symfony and HTTP clients keep throwing their own exceptions internally; the package classifies them centrally and keeps the original as `previous`:

| Exception | → Code | Category |
|---|---|---|
| `Illuminate\Validation\ValidationException` | `VALIDATION_ERROR` | VALIDATION |
| `Illuminate\Auth\AuthenticationException` | `UNAUTHORIZED` | AUTHENTICATION |
| `Illuminate\Auth\Access\AuthorizationException` | `FORBIDDEN` | AUTHORIZATION |
| `Illuminate\Database\Eloquent\ModelNotFoundException` | `NOT_FOUND` (or per-model, see below) | RESOURCE |
| `RecordsNotFoundException`, `RecordNotFoundException`, `BackedEnumCaseNotFoundException` | `NOT_FOUND` | RESOURCE |
| Symfony `HttpExceptionInterface` 401 / 403 / 404 / 405 / 413 / 419 / 429 / 503 / other 4xx / 5xx | `UNAUTHORIZED` / `FORBIDDEN` / `ROUTE_NOT_FOUND` / `METHOD_NOT_ALLOWED` / `REQUEST_TOO_LARGE` / `SECURITY_VIOLATION` / `TOO_MANY_REQUESTS` / `SERVICE_UNAVAILABLE` / `BAD_REQUEST` / `CODE_ERR` | various |
| `TokenMismatchException`, `InvalidSignatureException`, `SuspiciousOperationException`, `DecryptException` | `SECURITY_VIOLATION` | SECURITY |
| `ThrottleRequestsException` | `TOO_MANY_REQUESTS` | SECURITY |
| `Illuminate\Database\QueryException` (incl. `UniqueConstraintViolationException`), `PDOException` | `CODE_ERR` (500) | DATABASE |
| `DeadlockException`, `LostConnectionException`, SQLSTATE 40001/40P01 | `CODE_ERR` (500), retryable | DATABASE |
| `Illuminate\Http\Client\ConnectionException`, Guzzle `ConnectException` — cURL 28 / "timed out" | `EXTERNAL_SERVICE_TIMEOUT` (outcome unknown) | EXTERNAL_SERVICE |
| …— cURL 5/6/7/35/60 (never delivered) | `EXTERNAL_SERVICE_UNAVAILABLE`, retryable | EXTERNAL_SERVICE |
| …— other transport errors | `EXTERNAL_SERVICE_ERROR` (outcome unknown) | EXTERNAL_SERVICE |
| `Illuminate\Http\Client\RequestException`, Guzzle `BadResponseException` — 4xx / 408,504 / 429,503 / 5xx | `EXTERNAL_SERVICE_REJECTED` / `…_TIMEOUT` / `…_UNAVAILABLE` / `…_ERROR` | EXTERNAL_SERVICE |
| Symfony HttpClient `TimeoutExceptionInterface` / `TransportExceptionInterface`; PSR-18 `NetworkExceptionInterface` / `ClientExceptionInterface`; Guzzle `TransferException` | timeout / unavailable / error | EXTERNAL_SERVICE |
| `HttpResponseException` carrying a `RoayaPayJsonResponse` | rendered as-is | — |
| `HttpResponseException` with any other response | `RESPONSE_CONTRACT_VIOLATION` | INTERNAL |
| `BindingResolutionException`, `MissingAppKeyException`, unknown/invalid code registry | `CODE_ERR` | SYSTEM |
| PHP `Error` (`TypeError`, …) | `CODE_ERR`, critical | INTERNAL |
| Anything else | `CODE_ERR` | INTERNAL |

Laravel wraps some exceptions (e.g. `ModelNotFoundException` → `NotFoundHttpException`) before render callbacks run; the normalizer unwraps them so the real cause is classified. A `RoayaPayException` wrapped by another exception (e.g. inside a view) is found in the chain.

**Timeouts are not failures.** A provider timeout is reported as "outcome unknown" (`process_completed: null`) and is never retryable by default. Only failures where the request provably never left the server are marked retryable. See [§14](#14-transaction-identity-and-idempotency).

### Unknown codes

`BuildResponse::setCode('NOPE')` throws `UnknownApiCodeException` immediately. A thrown `RoayaPayException` with an unregistered code is rendered as `CODE_ERR` (HTTP 500) **and** reported as CRITICAL with the offending code — it fails safely and loudly. `php artisan roayapay:scan` catches it before deployment.

### Rendering pipeline (API requests)

1. Identify the exception → 2. normalize it to `RoayaPayException` → 3. resolve its registered code → 4. report via the Error Monitor according to its policy (once) → 5. build the four-key response → 6. HTTP 200/500 from the code definition.

Web routes keep Laravel's default rendering and reporting. If rendering itself fails, a minimal `CODE_ERR` is returned and the failure is reported.

## 11. Error monitoring

`ErrorMonitor` captures reportable exceptions with: exception class, safe (sanitized) message, file and line (internal only), request ID, transaction ID, method, route name/URI, path (no query string), authenticated user ID (without triggering authentication), UTC timestamp, category, code, severity, retryability, previous-exception chain and sanitized context.

- Expected outcomes (business, validation, authentication, not-found) are **not** reported.
- Authorization and security exceptions go to the security log.
- The same exception is reported once even though Laravel calls both `report()` and `render()`.
- `QueryException` messages are replaced by the driver message, so bound values (which may contain card numbers or e-mails) are never logged.
- A failing reporter never breaks the request or the other reporters.

Add an external monitor by implementing `ErrorReporter`:

```php
use RoayaPay\PlatformCore\Monitoring\Contracts\ErrorReporter;
use RoayaPay\PlatformCore\Monitoring\ErrorReport;

final class SentryReporter implements ErrorReporter
{
    public function report(ErrorReport $report, Throwable $original): void
    {
        \Sentry\withScope(function ($scope) use ($report, $original) {
            $scope->setTags(['api_code' => $report->apiCode, 'category' => $report->category]);
            $scope->setContext('roayapay', $report->toArray());
            \Sentry\captureException($original);
        });
    }
}
```

```php
// config/roayapay.php
'monitoring' => ['reporters' => [LogErrorReporter::class, App\Monitoring\SentryReporter::class]],
```

## 12. Logging

```php
use RoayaPay\PlatformCore\Logging\RoayaPayLogger;

$logger = app(RoayaPayLogger::class);

$logger->info('payment.created', ['payment_id' => $payment->id, 'amount' => '150.00']);
$logger->warning('provider.slow', ['ms' => 4200]);
$logger->error('settlement.mismatch', [...]);
$logger->critical('ledger.unbalanced', [...]);
$logger->security('login.blocked', ['user_id' => $id]);
```

- Levels: INFO, WARNING, ERROR, CRITICAL, SECURITY (security entries go to `logging.security_channel`, tagged `roayapay.level = SECURITY`).
- Every entry gets `roayapay.request_id` and `roayapay.transaction_id`.
- The request ID is also added to Laravel's log `Context`, so **all** log lines of the request (including `Log::info()`) carry it.
- Redaction is automatic: keys such as `password`, `password_confirmation`, `otp`, `pin`, `cvv`, `token`, `access_token`, `authorization`, `cookie`, `api_key`, `client_secret`, `private_key`, `card_number`, `iban`; plus values that look like Luhn-valid card numbers (masked to the last 4 digits), bearer/basic credentials, JWTs and PEM private keys. Add keys via `sanitizer.extra_keys`.
- A logging failure is swallowed (with a minimal `error_log` line); it never breaks the request.

## 13. Audit trail

Business events are separate from technical logs and are **append-only**.

```php
use RoayaPay\PlatformCore\Audit\AuditActor;
use RoayaPay\PlatformCore\Audit\AuditSubject;
use RoayaPay\PlatformCore\Audit\Contracts\AuditTrail;

DB::transaction(function () use ($wallet, $amount, $audit) {
    $wallet->decrement('balance', $amount);

    app(AuditTrail::class)->record(
        'balance.adjusted',
        AuditSubject::of('wallet', $wallet->id),
        AuditActor::merchant($wallet->merchant_id),
        ['amount' => $amount, 'currency' => 'EGP', 'reason' => 'transfer'],
        financial: true,
    );
});
```

- Records event ID (UUIDv7), action (`payment.created`, `payment.completed`, `payment.failed`, `merchant.updated`, `user.permission_changed`, `balance.adjusted`, `provider.configuration_changed`, …), actor (defaults to the authenticated user or `system`), subject, sanitized metadata, UTC timestamp with microseconds, request ID, transaction ID and a SHA-256 content hash.
- The `AuditTrail` and `AuditStore` contracts have **no update or delete operations**. `AuditEvent` is a readonly class.
- Financial events are never dropped: if the store fails, the exception propagates so the surrounding DB transaction rolls back. Non-financial failures are logged and swallowed (configurable).
- Stores: `database` (default, insert-only), `log` (dedicated channel), or your own `AuditStore` class.

**Database and retention considerations**

- Use the same connection as your financial tables and record financial events inside the same transaction as the money movement.
- Enforce append-only in the database as well: `REVOKE UPDATE, DELETE ON roayapay_audit_events FROM app_user;` (MySQL) or a `BEFORE UPDATE OR DELETE` trigger that raises (PostgreSQL).
- The published migration's `down()` refuses to drop the table in production.
- Retention: archive to cold storage (e.g. monthly partitions or exports to write-once storage); never delete in place. Keep at least the period your regulator and card-scheme rules require.
- The table is indexed by action, subject, actor, request ID, transaction ID and time.

## 14. Transaction identity and idempotency

### Transaction context

```php
use RoayaPay\PlatformCore\Transactions\TransactionContext;

$transactionId = app(TransactionContext::class)->begin();              // generates a UUIDv7
$transactionId = app(TransactionContext::class)->begin($payment->reference);
```

The transaction ID is attached to logs, error reports, audit events and exceptions, next to the request ID. The package does not own your transaction schema.

### Idempotency

```php
use RoayaPay\PlatformCore\Transactions\IdempotencyManager;

Route::post('payments', [PaymentController::class, 'store'])->middleware('roayapay.idempotency');

public function store(Request $request, IdempotencyManager $idempotency): RoayaPayJsonResponse
{
    $data = ApiValidator::validate($request->all(), $this->rules());

    return $idempotency->run('payments.create', (string) $request->header('Idempotency-Key'), $data, function () use ($data) {
        $payment = $this->payments->charge($data);   // your own model and schema

        return BuildResponse::setCode('SUCCESS')->setData(['payment_id' => $payment->id])->getResponse();
    });
}
```

| Repeated request with the same key | Result |
|---|---|
| Same payload, first attempt finished | Stored result **replayed** (in the caller's language), header `Idempotent-Replayed: true`. |
| Same payload, first attempt still running | `IDEMPOTENCY_IN_PROGRESS` (4091). |
| Same payload, first attempt crashed or lock expired | `IDEMPOTENCY_OUTCOME_UNKNOWN` (5010): verify, never auto-retry. |
| Different payload | `IDEMPOTENCY_CONFLICT` (4090). |

When the operation throws: validation/authentication/authorization/not-found → the key is released (nothing happened); a known definitive result such as `INSUFFICIENT_BALANCE` → stored and replayed; anything else → **outcome unknown**. A crash or timeout is never recorded as "failed".

- **Concurrency:** the database store relies on a `UNIQUE (scope, idempotency_key)` index — exactly one concurrent request wins (verified by a multi-process test). The cache store uses atomic `add()` + locks (Redis/Memcached/database cache; not `file`/`array` across servers; prefer the database store for money because caches can evict).
- **Ownership:** only the request that claimed a key can complete or release it.
- **Custom storage:** implement `Transactions\Contracts\IdempotencyStore` on your own schema and set `idempotency.store` to its class.

### Verifying a transaction after a timeout

1. The client timed out? **Do not create a new payment.** Retry the *same* request with the *same* `Idempotency-Key`: you get the replayed result, `IN_PROGRESS` (try later), or `OUTCOME_UNKNOWN`.
2. On `OUTCOME_UNKNOWN`, `PAYMENT_STATUS_UNKNOWN` or `EXTERNAL_SERVICE_TIMEOUT`, query the transaction status (your status endpoint, by transaction ID or reference). Implement `Transactions\Contracts\TransactionStatusResolver` to read your table and, if still pending, ask the provider's status API.
3. Only after the status is final (`COMPLETED` / `FAILED`) may operations staff reconcile and call `IdempotencyManager::forget($scope, $key)` to unblock the key.

Server side, the package never claims a payment failed because a client disconnected or a provider timed out.

## 15. Response enforcement (runtime)

`EnforceRoayaPayResponse` (part of `roayapay.api`) is a **fallback**:

- `RoayaPayJsonResponse` → passes.
- Files, streams, redirects, HTML, text → untouched.
- Any other JSON (`response()->json()`, raw arrays, `new JsonResponse`) → logged as `roayapay.response_contract_violation`; in `replace` mode replaced by `RESPONSE_CONTRACT_VIOLATION` (5005, HTTP 500, `process_completed: null` because the operation may have succeeded).
- `enforcement.mode = 'log'` only logs (useful while migrating legacy endpoints).
- Exempt an action explicitly with a reason:

```php
use RoayaPay\PlatformCore\Attributes\AllowsNonStandardResponse;

#[AllowsNonStandardResponse(reason: 'Acme webhook requires {"received": true}')]
public function acmeWebhook(Request $request) { ... }
```

or list route names in `enforcement.allowed_routes`. Attribute lookups are reflected once per action and memoized.

## 16. Static analysis and CI

The primary enforcement is a static check of your API code. It is token-based (no extra dependency, nothing executed) and detects:

| Rule | Detects |
|---|---|
| `response-json` | `response()->json()`, `response()->jsonp()`, `Response::json()` |
| `new-json-response` | `new JsonResponse(...)`, `new \Symfony\...\JsonResponse`, `JsonResponse::fromJsonString()` |
| `numeric-api-code` | `setCode(1000)`, `RoayaPayException::business(4001)`, `'code' => 4001` |
| `unregistered-api-code` | `setCode('X')`, `RoayaPayException::…('X')`, `new RoayaPayException('X')` with X not registered |
| `raw-array-return` | `return [...]`, `return array(...)`, `return compact(...)` from public actions of `*Controller` classes |

Suppress a line with `// roayapay-ignore: <reason>`; exempt an action with `#[AllowsNonStandardResponse]`.

```bash
php artisan roayapay:scan app/Http/Controllers/Api          # exit 1 on violations
php artisan roayapay:scan app/Http/Controllers/Api --json
vendor/bin/roayapay-scan app/Http/Controllers/Api --codes=config/roayapay_api_codes.php   # no Laravel boot
php artisan roayapay:codes --validate                       # registry check
```

In your own PHPUnit suite:

```php
use RoayaPay\PlatformCore\Testing\AssertsRoayaPayStandards;

final class ApiStandardTest extends TestCase
{
    use AssertsRoayaPayStandards;

    public function test_api_code_follows_the_roayapay_standard(): void
    {
        $this->assertApiCodeFollowsRoayaPayStandard([app_path('Http/Controllers/Api')]);
    }

    public function test_transfer_response(): void
    {
        $this->assertRoayaPayResponse($this->postJson('/api/transfer', [...], ['Accept-Language' => 'en']), 'SUCCESS');
    }
}
```

GitHub Actions example:

```yaml
name: roayapay-standard
on: [push, pull_request]
jobs:
  standard:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', extensions: mbstring, pdo_sqlite }
      - run: composer install --no-interaction --prefer-dist
      - run: php artisan roayapay:codes --validate
      - run: php artisan roayapay:scan app/Http/Controllers/Api
      - run: php artisan test
```

## 17. Laravel 12 integration

- **Auto-discovery** registers `PlatformCoreServiceProvider`. It adds middleware **aliases** and the opt-in **`roayapay.api` group** only; it never changes global middleware or your existing `web`/`api` groups.
- **Routes:** enable `routes/api.php` in `bootstrap/app.php` (`->withRouting(api: __DIR__.'/../routes/api.php', ...)`) and wrap routes in `roayapay.api`.
- **Request ID everywhere (optional):** to put a request ID on *every* response, including web routes, prepend it globally:

  ```php
  ->withMiddleware(function (Middleware $middleware) {
      $middleware->prepend(\RoayaPay\PlatformCore\Middleware\AssignRequestId::class);
  })
  ```

- **Exceptions:** registered automatically on Laravel's handler (`exceptions.auto_register`). To register explicitly instead, set it to `false` and use:

  ```php
  use RoayaPay\PlatformCore\Exceptions\RoayaPayExceptionHandling;

  ->withExceptions(function (Exceptions $exceptions) {
      RoayaPayExceptionHandling::configure($exceptions);
  })
  ```

  Other `report()` callbacks registered in `bootstrap/app.php` (e.g. Sentry's Laravel integration) still run; the package stops only Laravel's *default* log write for exceptions it already reported, to avoid duplicates.
- **Queues and console:** a `RoayaPayException` thrown in a job or command is reported through the Error Monitor with its policy; other exceptions there keep Laravel's default reporting.

## 18. Octane / FrankenPHP

- No package class declares a static property (enforced by an architecture test).
- `RequestContext` and `TransactionContext` live on the **Request object's attribute bag**, so a new request can never see the previous one's language, request ID or transaction ID — even if the container were reused.
- Services that touch request data are bound as `scoped` (flushed by Laravel/Octane between requests and queue jobs). Singletons are only immutable or class-metadata caches (code registry, sanitizer, per-language translators with a fixed locale, exception normalizer index, response-exemption memo).
- The global application locale is never changed (architecture test), and the shared translator is never mutated.
- Exception-handler and validator callbacks resolve services from `Container::getInstance()` **at call time**, so they use Octane's per-request sandbox rather than the boot-time application.
- `BuildResponse::setCode()` always returns a new builder.

## 19. Extension points

| Need | Extension point |
|---|---|
| New result codes | `config/roayapay_api_codes.php` |
| More languages | `language.supported` + messages in every code + `lang/vendor/roayapay/{lang}/*.php` |
| Field names / validation wording | `lang/{lang}/validation.php` → `attributes`/`custom`, or `lang/vendor/roayapay/{lang}/{fields,validation}.php` |
| Map an SDK exception to a code | `exceptions.map` → `[AcmeDeclined::class => ['code' => 'PAYMENT_DECLINED', 'category' => 'payment']]` |
| Complex mapping logic | Class implementing `ExceptionMapper` with `#[HandlesExceptions(...)]`, listed in `exceptions.mappers` (indexed once at boot; no per-request reflection) |
| Model-specific not-found codes | `exceptions.model_not_found` → `[Merchant::class => 'MERCHANT_NOT_FOUND']` |
| External monitoring | `ErrorReporter` implementations in `monitoring.reporters` |
| Audit storage | `AuditStore` implementation in `audit.store` |
| Idempotency storage | `IdempotencyStore` implementation in `idempotency.store` |
| Post-timeout verification | Implement `TransactionStatusResolver` |
| Exempt an endpoint | `#[AllowsNonStandardResponse(reason: ...)]` or `enforcement.allowed_routes` |
| Extra redaction | `sanitizer.extra_keys` |

Example mapper:

```php
use RoayaPay\PlatformCore\Exceptions\Attributes\HandlesExceptions;
use RoayaPay\PlatformCore\Exceptions\Contracts\ExceptionMapper;

#[HandlesExceptions(AcmeDeclinedException::class, AcmeTimeoutException::class)]
final class AcmeGatewayMapper implements ExceptionMapper
{
    public function map(Throwable $e): ?RoayaPayException
    {
        return match (true) {
            $e instanceof AcmeDeclinedException => RoayaPayException::payment('PAYMENT_DECLINED', context: ['reason' => $e->reason]),
            $e instanceof AcmeTimeoutException => RoayaPayException::external('PAYMENT_STATUS_UNKNOWN', retryable: false),
            default => null,
        };
    }
}
```

## 20. Known limitations

- **Tested Laravel version is 12.70.2**, the newest 12.x release available when this package was built and tested. Your application runs 12.71.1; the `^12.0` constraints accept it, but that exact release was not part of the test run. Run this package's suite (or your application's) once against 12.71.1 to confirm.
- Inline validation messages passed to `ApiValidator::make()`/FormRequest `messages()` are used verbatim; they are not translated automatically.
- Custom validation rule classes that build their message with the global `__()` helper use the application locale; prefer `$fail()` with translation keys or put the messages in `lang/vendor/roayapay/{lang}/validation.php`.
- The response scanner is token-based: it catches the documented patterns in the scanned paths but is not a type-aware analyzer (e.g. it cannot see a JSON response built in a helper outside the scanned paths). The runtime middleware covers the rest.
- HTTP-client classification relies on cURL error numbers / provider status codes. Provider-specific declines must be mapped by the application (mapper or `exceptions.map`) to become `PAYMENT_DECLINED`.
- The cache idempotency store is only as durable as the cache; use the database store for financial operations.
- `LICENSE` is a proprietary placeholder; replace it with RoayaPay's legal text.
