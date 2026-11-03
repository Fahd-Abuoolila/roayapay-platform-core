<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Response;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Override;
use RoayaPay\PlatformCore\Codes\CodeDefinition;
use RoayaPay\PlatformCore\Support\BilingualMessage;
use stdClass;

/**
 * The only approved JSON response type for RoayaPay APIs.
 *
 * Its body is guaranteed to contain exactly four keys – status (bool),
 * code (int), message (string) and data (object|array) – even if someone
 * calls setData() on it later.
 *
 * Create it through BuildResponse, ApiValidator or the exception renderer;
 * never instantiate it directly in application code.
 */
class RoayaPayJsonResponse extends JsonResponse
{
    public const array KEYS = ['status', 'code', 'message', 'data'];

    public const int ENCODING_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /** The symbolic code this response was built from (e.g. INSUFFICIENT_BALANCE). */
    public private(set) ?string $apiCode = null;

    /** The custom bilingual message, if one was used (needed to replay in another language). */
    public private(set) ?BilingualMessage $messageOverride = null;

    /** The status override, if one was used. */
    public private(set) ?bool $statusOverride = null;

    /**
     * @internal Use BuildResponse / ResponseFactory.
     *
     * @param  array{status: bool, code: int, message: string, data: mixed}  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(array $payload, int $status, array $headers = [], ?CodeDefinition $definition = null)
    {
        parent::__construct($payload, $status, $headers, self::ENCODING_OPTIONS);

        $this->apiCode = $definition?->key;
    }

    /**
     * @internal
     */
    public function withOverrides(?BilingualMessage $message, ?bool $status): static
    {
        $this->messageOverride = $message;
        $this->statusOverride = $status;

        return $this;
    }

    /**
     * Rejects any payload that does not satisfy the RoayaPay four-key contract.
     */
    #[Override]
    public function setData($data = []): static
    {
        if (! is_array($data) || array_keys($data) !== self::KEYS) {
            throw new InvalidArgumentException('A RoayaPay response body must contain exactly the keys: status, code, message, data (in this order).');
        }

        if (! is_bool($data['status']) || ! is_int($data['code']) || ! is_string($data['message'])) {
            throw new InvalidArgumentException('RoayaPay response types: status must be bool, code int, message string.');
        }

        if ($data['data'] === null || $data['data'] === []) {
            $data['data'] = new stdClass();
        }

        if (! is_array($data['data']) && ! is_object($data['data'])) {
            throw new InvalidArgumentException('RoayaPay response "data" must be an object or array.');
        }

        return parent::setData($data);
    }

    /**
     * The decoded "data" payload (associative array).
     *
     * @return array<mixed>
     */
    public function payloadData(): array
    {
        return (array) ($this->getData(true)['data'] ?? []);
    }
}
