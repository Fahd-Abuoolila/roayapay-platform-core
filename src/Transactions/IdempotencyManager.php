<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Transactions;

use Closure;
use Illuminate\Contracts\Container\Container;
use RoayaPay\PlatformCore\Exceptions\ErrorCategory;
use RoayaPay\PlatformCore\Exceptions\RoayaPayException;
use RoayaPay\PlatformCore\Response\ResponseFactory;
use RoayaPay\PlatformCore\Response\RoayaPayJsonResponse;
use RoayaPay\PlatformCore\Transactions\Contracts\IdempotencyStore;
use Throwable;

/**
 * Runs an operation at most once per (scope, idempotency key).
 *
 *     return $idempotency->run('payments.create', $key, $request->all(), function () use (...) {
 *         $payment = $this->payments->create(...);
 *         return BuildResponse::setCode('SUCCESS')->setData($payment)->getResponse();
 *     });
 *
 * Outcomes for a repeated key:
 *  - same payload, finished            → the stored result is replayed (in the caller's language)
 *  - same payload, still running       → IDEMPOTENCY_IN_PROGRESS
 *  - same payload, outcome unknown     → IDEMPOTENCY_OUTCOME_UNKNOWN (verify the transaction status)
 *  - different payload                 → IDEMPOTENCY_CONFLICT
 *
 * When the operation throws:
 *  - validation / authentication / authorization / not-found → key released (nothing happened)
 *  - a known definitive result (HTTP 200 code, process_completed=true, e.g. INSUFFICIENT_BALANCE)
 *    → stored and replayed for retries
 *  - anything else → OUTCOME UNKNOWN. A crash or timeout never counts as "failed";
 *    the key stays blocked until the outcome is verified and the key is forgotten.
 */
final readonly class IdempotencyManager
{
    public const string KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\-]{7,127}$/';

    private const array RELEASE_ON = [
        ErrorCategory::VALIDATION, ErrorCategory::AUTHENTICATION, ErrorCategory::AUTHORIZATION, ErrorCategory::RESOURCE,
    ];

    public function __construct(
        private IdempotencyStore $store,
        private Container $container,
        private int $lockTtlSeconds = 300,
        private int $retentionSeconds = 86400,
    ) {}

    /**
     * @param  array<mixed>  $payload  The request data that defines "the same request".
     * @param  Closure(): RoayaPayJsonResponse  $operation
     *
     * @throws RoayaPayException
     */
    public function run(string $scope, string $key, array $payload, Closure $operation): RoayaPayJsonResponse
    {
        if (! self::isValidKey($key)) {
            throw new RoayaPayException('IDEMPOTENCY_KEY_REQUIRED', ErrorCategory::VALIDATION);
        }

        $this->container->make(TransactionContext::class)->setIdempotencyKey($key);

        $factory = $this->container->make(ResponseFactory::class);
        $claim = $this->store->claim($scope, $key, $this->fingerprint($scope, $payload), $this->lockTtlSeconds, $this->retentionSeconds);

        return match ($claim->state) {
            IdempotencyState::REPLAY => $this->replay($claim->result, $factory),
            IdempotencyState::CONFLICT => throw RoayaPayException::business('IDEMPOTENCY_CONFLICT'),
            IdempotencyState::IN_PROGRESS => throw RoayaPayException::business('IDEMPOTENCY_IN_PROGRESS'),
            IdempotencyState::OUTCOME_UNKNOWN => throw RoayaPayException::business('IDEMPOTENCY_OUTCOME_UNKNOWN')
                ->withReporting(\RoayaPay\PlatformCore\Exceptions\ReportingPolicy::DONT_REPORT),
            IdempotencyState::ACQUIRED => $this->execute($scope, $key, (string) $claim->ownerToken, $operation, $factory),
        };
    }

    public static function isValidKey(?string $key): bool
    {
        return $key !== null && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Administrative: forget a key once its real outcome has been verified.
     */
    public function forget(string $scope, string $key): void
    {
        $this->store->forget($scope, $key);
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function fingerprint(string $scope, array $payload): string
    {
        return hash('sha256', $scope."\0".json_encode($this->canonical($payload), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param  Closure(): RoayaPayJsonResponse  $operation
     */
    private function execute(string $scope, string $key, string $owner, Closure $operation, ResponseFactory $factory): RoayaPayJsonResponse
    {
        try {
            $response = $operation();
        } catch (RoayaPayException $exception) {
            $this->settleFailure($scope, $key, $owner, $exception, $factory);

            throw $exception;
        } catch (Throwable $throwable) {
            $this->store->markOutcomeUnknown($scope, $key, $owner);

            throw $throwable;
        }

        if (! $response instanceof RoayaPayJsonResponse || $response->apiCode === null) {
            $this->store->markOutcomeUnknown($scope, $key, $owner);

            throw RoayaPayException::internal('RESPONSE_CONTRACT_VIOLATION', [
                'reason' => 'Idempotent operation must return a RoayaPayJsonResponse.',
                'returned' => get_debug_type($response),
            ]);
        }

        $this->store->complete($scope, $key, $owner, StoredResult::fromResponse($response), $this->retentionSeconds);

        return $response;
    }

    private function settleFailure(string $scope, string $key, string $owner, RoayaPayException $exception, ResponseFactory $factory): void
    {
        if (in_array($exception->category, self::RELEASE_ON, true)) {
            $this->store->release($scope, $key, $owner);

            return;
        }

        $definition = $factory->registry()->find($exception->apiCode);

        if ($definition !== null && $definition->http === 200 && $definition->processCompleted === true) {
            $this->store->complete($scope, $key, $owner, StoredResult::fromException($exception, $factory), $this->retentionSeconds);

            return;
        }

        $this->store->markOutcomeUnknown($scope, $key, $owner);
    }

    private function replay(?StoredResult $result, ResponseFactory $factory): RoayaPayJsonResponse
    {
        $response = $result?->toResponse($factory)
            ?? throw RoayaPayException::internal(context: ['reason' => 'Replay without stored result']);

        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonical(...), $value);
    }
}
