# RoayaPay Platform Core — Architecture

This note records the main design decisions and the reasons behind them.

## 1. Structure

```text
src/
├── Analysis/        ResponseContractScanner, Violation          (static CI checks)
├── Attributes/      AllowsNonStandardResponse                   (explicit, reasoned exemptions)
├── Audit/           AuditTrail, AuditEvent/Actor/Subject, Contracts/{AuditTrail,AuditStore}, Stores/
├── Codes/           ApiCodeRegistry, CodeDefinition, RegistryValidator, exceptions
├── Console/         roayapay:scan, roayapay:codes
├── Exceptions/      RoayaPayException (the single public API), ErrorCategory, Severity, ReportingPolicy,
│                    ExceptionNormalizer, ExceptionRenderer, ExceptionReporter, RoayaPayExceptionHandling,
│                    Attributes/HandlesExceptions, Contracts/ExceptionMapper
├── Logging/         RoayaPayLogger, LogLevel
├── Middleware/      AssignRequestId, RequireApiLanguage, EnforceRoayaPayResponse, RequireIdempotencyKey
├── Monitoring/      ErrorMonitor, ErrorReport, LogErrorReporter, RequestContext, Contracts/ErrorReporter
├── Providers/       PlatformCoreServiceProvider
├── Response/        BuildResponse, ResponseFactory, RoayaPayJsonResponse
├── Support/         Sanitizer, LanguageResolver, ApiRequestDetector, BilingualMessage, RequestScoped, ...
├── Testing/         AssertsRoayaPayStandards (PHPUnit helpers for applications)
├── Transactions/    TransactionContext, IdempotencyManager, StoredResult, Contracts/, Stores/
└── Validation/      ApiValidator, ValidationMessageResolver, FieldNameResolver,
                     LanguageTranslator(s), FrameworkValidatorLocalizer
config/  roayapay.php, roayapay_api_codes.php
lang/    {ar,en}/{messages,fields,validation}.php
database/migrations/*.stub
bin/roayapay-scan
```

Changes against the proposed structure, and why:

- **`config/roayapay.php`** was added next to `roayapay_api_codes.php`. The registry must stay a pure list of codes; operational settings (languages, channels, stores, enforcement mode) need their own file.
- **`lang/{ar,en}/validation.php`** was added. Laravel ships no Arabic validation lines, and the English defaults ("The email field is required.") do not match the RoayaPay house style ("Email is required").
- **`Analysis/`, `Console/`, `Testing/`, `bin/`** implement the static enforcement required by the brief.
- **`Attributes/`** and **`Exceptions/Attributes/`** hold the two PHP attributes (see §7).
- **`Monitoring/RequestContext`** is where the brief placed it; `TransactionContext` lives in `Transactions/`.

## 2. Response contract

`RoayaPayJsonResponse` extends `Illuminate\Http\JsonResponse` and overrides `setData()` to reject any body that is not exactly `status, code, message, data` with the right types. The contract therefore holds by construction, not by convention. `ResponseFactory` is the single place that assembles the body. `BuildResponse::setCode()` is a static *constructor* that returns a fresh instance; all builder state lives on that instance.

## 3. Code registry

A plain PHP config array is the source of truth. It is validated once when the singleton registry is built (duplicates, types, HTTP 200/500 only, languages, required package codes, unknown fields) and can be checked in CI with `roayapay:codes --validate`. It works with `config:cache` and needs no reflection. `process_completed` is tri-state (`true`/`false`/`null`) because a payment API must be able to say "outcome unknown".

## 4. Language without global locale

`RequireApiLanguage` validates `Accept-Language` strictly and stores it in `RequestContext`. Nothing calls `App::setLocale()` (enforced by an architecture test). Every message is resolved for an explicit language:

- code messages come from the registry definition for that language;
- validation messages come from a `LanguageTranslator` bound to that language.

## 5. Validation

A `MessageBag` already contains text in one language; translating it after the fact is unreliable. The package instead makes sure each API validator is *created* with a translator for the request language:

- `LanguageTranslators` keeps one `LanguageTranslator` per supported language. Each has a fixed locale and fallback equal to that language, shares Laravel's file loader, and is never mutated afterwards — safe as a singleton.
- `ApiValidator::make()` builds the validator through Laravel's own factory (so custom rules, extensions and the DB presence verifier still work) and calls `setTranslator()`.
- `FrameworkValidatorLocalizer` installs a resolver on the framework factory (wrapping any existing resolver) so `$request->validate()`, `Validator::make()` and FormRequests get the same treatment on API requests only.
- `LanguageTranslator` resolves `validation.<rule>` from `roayapay::validation` first, merges `roayapay::fields` under the application's `validation.attributes`, leaves `validation.custom` untouched, and returns a same-language generic message for unknown rules.

## 6. Exceptions

**One public class.** `RoayaPayException` is `final` and extends `RuntimeException`; all factories return it. Category, severity, reporting policy and retryability are data on that one class, not subclasses. Internal developer errors (`UnknownApiCodeException`, `InvalidApiCodeRegistryException`) are `LogicException`s — they signal a bug in configuration or code, like a `TypeError`, and are not part of the application error API.

**Category ≠ HTTP status.** The category provides defaults; the registered code defines code/status/message/HTTP.

**Normalization is central.** `ExceptionNormalizer` converts framework, Symfony, database and HTTP-client exceptions, keeps the original as `previous`, and unwraps Laravel's "prepared" HTTP wrappers so the real cause is classified. Unknown exceptions become INTERNAL/CODE_ERR — never business results — and are reported.

**Integration.** `RoayaPayExceptionHandling` registers a render callback (API requests only → `ExceptionRenderer`) and a report callback (`ExceptionReporter`). The `ErrorMonitor` de-duplicates by exception identity (a `WeakMap` scoped to the request), because Laravel calls both `report()` and `render()` for the same exception. Laravel's built-in `dontReport` list still applies to framework exceptions; their reporting (e.g. authorization denials to the security log) happens in the render path.

**Payments.** Transport failures are split into "never delivered" (safe to retry) and "may have been processed" (timeout / provider 5xx → outcome unknown, `process_completed: null`, not retryable). The package never turns a timeout into a failed payment.

## 7. PHP 8.4 features and where attributes were (not) used

Used where they add real value:

- **Asymmetric visibility** (`public private(set)`) on `RoayaPayException`, `RequestContext`, `TransactionContext`, `RoayaPayJsonResponse`: callers read properties directly; only the owning class can change them.
- **`new` without parentheses** for fluent one-liners (`new RegistryValidator()->validate(...)`).
- **`array_any()`** in the sanitizer, normalizer and scanner.
- **Readonly classes**, typed class constants, enums, constructor promotion, `#[\Override]`.

Attributes:

- `#[HandlesExceptions(...)]` on exception mappers. The normalizer reads it **once** at registration and builds a class → mapper index, so the right mapper is found by a hash lookup, not by calling every mapper or reflecting per request. A mapper without the attribute is rejected loudly. A test normalizes 5,000 exceptions to guard against per-call reflection.
- `#[AllowsNonStandardResponse(reason: ...)]` on controller actions. It is reflected once per action and memoized in a singleton that caches class metadata only. The static scanner honours it too.

Deliberately **not** attributes: the code registry. A config array is simpler, cacheable with `config:cache`, reviewable in one file and needs no discovery or reflection. Attribute-based code definitions would scatter codes across classes — the opposite of the "one central registry" requirement.

## 8. Long-running workers

- No static properties anywhere in `src/` (architecture test).
- `RequestContext`/`TransactionContext` are stored on the `Request` object's attributes; outside HTTP they come from a container-scoped holder.
- Services that read request data are `scoped`; singletons are immutable or metadata caches only.
- Callbacks that outlive a request (exception handler, validator resolver) resolve from `Container::getInstance()` at call time so Octane's per-request sandbox is used.

## 9. Audit and idempotency

**Audit**: contracts have no update/delete; events are readonly and hashed; financial write failures propagate so the business transaction rolls back. The database store only issues `INSERT`s; database-level grants/triggers are documented as the second line of defence.

**Idempotency**: correctness comes from the storage primitive — a unique index (database store) or atomic `add()` + lock (cache store) — plus owner tokens so a stale worker cannot overwrite a result. Expired in-progress claims become "outcome unknown", never silently re-acquired, because the first attempt may have reached the provider. Results are stored by *symbolic* code so replays are rendered in the replaying request's language. The package does not define a transaction schema; `TransactionStatusResolver` is the application's hook for post-timeout verification.

## 10. Static enforcement

`ResponseContractScanner` tokenizes PHP (`PhpToken`) and tracks class/method/closure scopes and attributes. It has no dependencies, executes nothing, and is available as an Artisan command, a standalone `vendor/bin/roayapay-scan`, and a PHPUnit trait. The runtime middleware is only the fallback. The package scans its own `src/` in its test suite (and that check found and removed a hard-coded code in an early version of the renderer).
