<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Exceptions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RoayaPay\PlatformCore\Monitoring\ErrorMonitor;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use RoayaPay\PlatformCore\Response\ResponseFactory;
use RoayaPay\PlatformCore\Response\RoayaPayJsonResponse;
use RoayaPay\PlatformCore\Support\ApiRequestDetector;
use RoayaPay\PlatformCore\Transactions\TransactionContext;
use RoayaPay\PlatformCore\Validation\ApiValidator;
use Throwable;

/**
 * Renders any exception on an API request as a standard RoayaPay response.
 *
 * 1. Identify + normalize the exception (ExceptionNormalizer).
 * 2. Resolve its registered code. An unregistered code is a programming error:
 *    it becomes CODE_ERR and is reported as CRITICAL (never silently).
 * 3. Report through the ErrorMonitor according to the reporting policy (once).
 * 4. Build the four-key body; the HTTP status comes from the code definition
 *    (200 known result / 500 processing failure).
 *
 * Returns null for non-API requests so Laravel's default rendering applies.
 * Never exposes traces, SQL, paths, previous exceptions or internal context.
 */
final readonly class ExceptionRenderer
{
    public function __construct(
        private Container $container,
        private ExceptionNormalizer $normalizer,
        private ApiRequestDetector $detector,
    ) {}

    public function render(Throwable $exception, Request $request): ?RoayaPayJsonResponse
    {
        if (! $this->detector->isApi($request)) {
            return null;
        }

        // A RoayaPay response thrown through HttpResponseException is already compliant.
        if ($exception instanceof HttpResponseException && $exception->getResponse() instanceof RoayaPayJsonResponse) {
            return $exception->getResponse();
        }

        try {
            return $this->renderNormalized($exception);
        } catch (Throwable $renderFailure) {
            return $this->lastResort($exception, $renderFailure);
        }
    }

    private function renderNormalized(Throwable $exception): RoayaPayJsonResponse
    {
        $factory = $this->container->make(ResponseFactory::class);
        $monitor = $this->container->make(ErrorMonitor::class);
        $context = $this->container->make(RequestContext::class);

        // Exceptions raised before route middleware ran (e.g. unknown route) still get a correlation ID.
        if ($context->requestId === null) {
            $context->setRequestId((string) Str::uuid7());
        }

        $normalized = $this->normalizer->normalize($exception)->withCorrelation(
            $context->requestId,
            $this->container->make(TransactionContext::class)->transactionId,
        );

        $definition = $factory->registry()->find($normalized->apiCode);

        if ($definition === null) {
            $unknown = RoayaPayException::internal(
                context: ['reason' => 'unregistered_api_code', 'api_code' => $normalized->apiCode],
                previous: $normalized,
            )->withSeverity(Severity::CRITICAL);

            $monitor->capture($unknown, $exception);

            return $factory->make($factory->registry()->get('CODE_ERR'));
        }

        $monitor->capture($normalized, $exception);

        if ($normalized->hasValidationErrors()) {
            return $factory->make(
                definition: $definition,
                data: $normalized->data,
                resolvedMessage: ApiValidator::message($normalized->validationErrors),
            );
        }

        return $factory->make($definition, $normalized->data, $normalized->messageOverride);
    }

    private function lastResort(Throwable $exception, Throwable $renderFailure): RoayaPayJsonResponse
    {
        try {
            $this->container->make(ErrorMonitor::class)->capture(
                RoayaPayException::internal(
                    context: ['reason' => 'render_failure', 'render_exception' => $renderFailure::class],
                    previous: $exception,
                )->withSeverity(Severity::CRITICAL),
                $renderFailure,
            );
        } catch (Throwable) {
            // Reporting must not prevent a response.
        }

        // Minimal CODE_ERR response that does not depend on the request language or the response factory.
        $definition = $this->codeErrDefinition();

        return new RoayaPayJsonResponse(
            [
                'status' => false,
                'code' => $definition['code'],
                'message' => $definition['message'],
                'data' => new \stdClass(),
            ],
            500,
        );
    }

    /**
     * @return array{code: int, message: string}
     */
    private function codeErrDefinition(): array
    {
        try {
            $definition = $this->container->make(\RoayaPay\PlatformCore\Codes\ApiCodeRegistry::class)->get('CODE_ERR');

            return ['code' => $definition->code, 'message' => $definition->message('en')];
        } catch (Throwable) {
            // The registry itself may be the failure: fall back to the package's shipped definition.
            $shipped = (require __DIR__.'/../../config/roayapay_api_codes.php')['CODE_ERR'];

            return ['code' => $shipped['code'], 'message' => $shipped['message']['en']];
        }
    }
}
