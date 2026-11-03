<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Transactions;

use RoayaPay\PlatformCore\Exceptions\RoayaPayException;
use RoayaPay\PlatformCore\Response\ResponseFactory;
use RoayaPay\PlatformCore\Response\RoayaPayJsonResponse;
use RoayaPay\PlatformCore\Support\BilingualMessage;

/**
 * The language-independent result of an idempotent operation.
 *
 * Stores the SYMBOLIC code, data and any bilingual message override, so a
 * replay is rendered in the language of the replaying request.
 */
final readonly class StoredResult
{
    /**
     * @param  array<mixed>  $data
     * @param  array<string, string>|null  $message
     */
    public function __construct(
        public string $code,
        public array $data = [],
        public ?array $message = null,
        public ?bool $status = null,
    ) {}

    public static function fromResponse(RoayaPayJsonResponse $response): self
    {
        return new self(
            code: (string) $response->apiCode,
            data: $response->payloadData(),
            message: $response->messageOverride?->messages,
            status: $response->statusOverride,
        );
    }

    public static function fromException(RoayaPayException $exception, ResponseFactory $factory): self
    {
        return new self(
            code: $exception->apiCode,
            data: json_decode((string) json_encode($factory->normalizeData($exception->data)), true) ?: [],
            message: $exception->messageOverride?->messages,
        );
    }

    public function toResponse(ResponseFactory $factory): RoayaPayJsonResponse
    {
        $definition = $factory->registry()->get($this->code);
        $message = $this->message !== null ? BilingualMessage::from($this->message, $factory->registry()->languages) : null;

        return $factory->make($definition, $this->data, $message, $this->status);
    }

    /**
     * @return array{code: string, data: array<mixed>, message: array<string, string>|null, status: bool|null}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'data' => $this->data, 'message' => $this->message, 'status' => $this->status];
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        return new self(
            code: (string) $array['code'],
            data: (array) ($array['data'] ?? []),
            message: isset($array['message']) && is_array($array['message']) ? $array['message'] : null,
            status: isset($array['status']) && is_bool($array['status']) ? $array['status'] : null,
        );
    }
}
