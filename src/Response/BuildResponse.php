<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Response;

use Illuminate\Container\Container;
use RoayaPay\PlatformCore\Codes\CodeDefinition;
use RoayaPay\PlatformCore\Codes\UnknownApiCodeException;
use RoayaPay\PlatformCore\Support\BilingualMessage;

/**
 * Fluent builder for RoayaPay API responses.
 *
 *     return BuildResponse::setCode('SUCCESS')->setData($data)->getResponse();
 *
 * setCode() creates a NEW builder every time; no response state is ever stored
 * statically, so concurrent builders (and Octane workers) never share state.
 */
final class BuildResponse
{
    private mixed $data = null;

    private ?BilingualMessage $message = null;

    private ?bool $status = null;

    private function __construct(
        private readonly CodeDefinition $definition,
        private readonly ResponseFactory $factory,
    ) {}

    /**
     * Start a response for a registered symbolic code.
     *
     * @throws UnknownApiCodeException when the code is not registered.
     */
    public static function setCode(string $code): self
    {
        $factory = Container::getInstance()->make(ResponseFactory::class);

        return new self($factory->registry()->get($code), $factory);
    }

    /**
     * Optional response data. Arrays, objects, Arrayable, JsonSerializable and
     * JsonResource are accepted. Empty data is always rendered as {}.
     *
     * @param  array<mixed>|object|null  $data
     */
    public function setData(array|object|null $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Optional message override. Both "ar" and "en" must be non-empty strings.
     *
     * @param  array{ar: string, en: string}  $message
     *
     * @throws \InvalidArgumentException
     */
    public function setMessage(array $message): self
    {
        $this->message = BilingualMessage::from($message, $this->factory->registry()->languages);

        return $this;
    }

    /**
     * Optional override of the registered default status.
     */
    public function setStatus(bool $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getResponse(): RoayaPayJsonResponse
    {
        return $this->factory->make($this->definition, $this->data, $this->message, $this->status);
    }

    /**
     * The definition this builder was created from.
     */
    public function definition(): CodeDefinition
    {
        return $this->definition;
    }
}
