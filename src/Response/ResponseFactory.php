<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Response;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use JsonSerializable;
use RoayaPay\PlatformCore\Codes\ApiCodeRegistry;
use RoayaPay\PlatformCore\Codes\CodeDefinition;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use RoayaPay\PlatformCore\Support\BilingualMessage;
use RoayaPay\PlatformCore\Support\LanguageResolver;
use stdClass;

/**
 * Low-level factory that turns a code definition into a RoayaPayJsonResponse.
 *
 * Application code should use BuildResponse. This class is used by the
 * builder, the validator and the exception renderer, and is the single place
 * where the four-key body is assembled.
 */
final readonly class ResponseFactory
{
    public function __construct(
        private Container $container,
        private ApiCodeRegistry $registry,
        private LanguageResolver $languages,
        private string $requestIdHeader = 'X-Request-Id',
    ) {}

    public function registry(): ApiCodeRegistry
    {
        return $this->registry;
    }

    public function language(): string
    {
        return $this->languages->current();
    }

    /**
     * Build a response from a definition.
     *
     * @param  string|null  $resolvedMessage  A message already in the request language (e.g. validation output).
     */
    public function make(
        CodeDefinition $definition,
        mixed $data = null,
        ?BilingualMessage $message = null,
        ?bool $status = null,
        ?string $resolvedMessage = null,
    ): RoayaPayJsonResponse {
        $language = $this->languages->current();

        $text = $resolvedMessage ?? $message?->in($language) ?? $definition->message($language);

        $response = new RoayaPayJsonResponse(
            [
                'status' => $status ?? $definition->status,
                'code' => $definition->code,
                'message' => $text,
                'data' => $this->normalizeData($data),
            ],
            $definition->http,
            $this->headers($language),
            $definition,
        );

        return $response->withOverrides($message, $status);
    }

    /**
     * Converts supplied data into a JSON object/array; "no data" always becomes {}.
     */
    public function normalizeData(mixed $data): object|array
    {
        if ($data instanceof JsonResource) {
            $request = $this->container->bound('request') ? $this->container->make('request') : Request::create('/');
            $data = $data->resolve($request);
        } elseif ($data instanceof Arrayable) {
            $data = $data->toArray();
        } elseif ($data instanceof JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        if ($data === null || $data === []) {
            return new stdClass();
        }

        if (! is_array($data) && ! is_object($data)) {
            throw new \InvalidArgumentException(sprintf('RoayaPay response data must be an array or object, %s given.', get_debug_type($data)));
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $language): array
    {
        $headers = ['Content-Language' => $language];

        $context = $this->container->make(RequestContext::class);

        if ($context->requestId !== null) {
            $headers[$this->requestIdHeader] = $context->requestId;
        }

        return $headers;
    }
}
