<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Codes;

/**
 * The central, immutable registry of RoayaPay API codes.
 *
 * Built once from config('roayapay_api_codes') and validated on construction.
 * It holds no request-specific state, so it is safe as a singleton under Octane.
 */
final class ApiCodeRegistry
{
    /** @var array<string, CodeDefinition> */
    private readonly array $definitions;

    /** @var array<int, string> */
    private readonly array $byInteger;

    /**
     * @param  array<mixed>  $config
     * @param  list<string>  $languages
     *
     * @throws InvalidApiCodeRegistryException
     */
    public function __construct(array $config, public readonly array $languages = ['ar', 'en'])
    {
        $errors = new RegistryValidator()->validate($config, $languages);

        if ($errors !== []) {
            throw new InvalidApiCodeRegistryException($errors);
        }

        $definitions = [];
        $byInteger = [];

        foreach ($config as $key => $definition) {
            $definitions[$key] = new CodeDefinition(
                key: $key,
                code: $definition['code'],
                http: $definition['http'],
                status: $definition['status'],
                processCompleted: array_key_exists('process_completed', $definition)
                    ? $definition['process_completed']
                    : $definition['http'] === 200,
                messages: array_intersect_key($definition['message'], array_flip($languages)),
            );
            $byInteger[$definition['code']] = $key;
        }

        $this->definitions = $definitions;
        $this->byInteger = $byInteger;
    }

    /**
     * @throws UnknownApiCodeException
     */
    public function get(string $key): CodeDefinition
    {
        return $this->definitions[$key] ?? throw UnknownApiCodeException::forKey($key);
    }

    public function find(string $key): ?CodeDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function findByInteger(int $code): ?CodeDefinition
    {
        $key = $this->byInteger[$code] ?? null;

        return $key === null ? null : $this->definitions[$key];
    }

    /**
     * @return array<string, CodeDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
