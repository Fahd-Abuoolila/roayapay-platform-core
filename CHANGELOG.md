# Changelog

All notable changes to `roayapay/platform-core` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-03

First release. Targets PHP 8.4 and Laravel 12.x only.

### Added

- Four-key RoayaPay response contract with `BuildResponse`, `ResponseFactory` and the contract-enforcing `RoayaPayJsonResponse`.
- Central, validated API code registry (`config/roayapay_api_codes.php`) with HTTP 200/500 policy and tri-state `process_completed`.
- Mandatory `Accept-Language` (`ar`/`en`) middleware with request-scoped language and no global-locale mutation.
- Bilingual validation (`ApiValidator`) with language-bound translators, Arabic/English messages for 56 rules, translated field names, and localization of `$request->validate()`, `Validator::make()` and FormRequests on API requests.
- `RoayaPayException` as the single application-level exception API, with factories `business`, `payment`, `validation`, `unauthorized`, `forbidden`, `notFound`, `external`, `database`, `security`, `internal`, `system`, `fromThrowable`; categories, severity, reporting policy, retryability, correlation IDs.
- Central `ExceptionNormalizer` for Laravel, Symfony, database, Laravel HTTP client, Guzzle, Symfony HttpClient and PSR-18 exceptions; config mappings and `#[HandlesExceptions]` mappers.
- Exception renderer/reporter integration with Laravel 12's handler (API requests only).
- `ErrorMonitor` with sanitized reports, de-duplication and pluggable `ErrorReporter`s.
- `RoayaPayLogger` (INFO, WARNING, ERROR, CRITICAL, SECURITY) with automatic redaction and request/transaction correlation.
- Request ID middleware (validated incoming IDs, UUIDv7 generation, response header, Laravel log context).
- Append-only audit trail with database, log and in-memory stores.
- Transaction context, `IdempotencyManager`, database and cache idempotency stores, `Idempotency-Key` middleware, `TransactionStatusResolver` contract.
- Runtime response enforcement middleware with `#[AllowsNonStandardResponse]` and route allow-list.
- Static response scanner (`roayapay:scan`, `vendor/bin/roayapay-scan`, `AssertsRoayaPayStandards`) and `roayapay:codes`.
- Publishable config, translations and migrations.
