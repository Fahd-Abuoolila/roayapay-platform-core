<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Analysis;

final readonly class Violation
{
    public const string RESPONSE_JSON = 'response-json';

    public const string NEW_JSON_RESPONSE = 'new-json-response';

    public const string NUMERIC_CODE = 'numeric-api-code';

    public const string UNKNOWN_CODE = 'unregistered-api-code';

    public const string RAW_ARRAY_RETURN = 'raw-array-return';

    public function __construct(
        public string $file,
        public int $line,
        public string $rule,
        public string $message,
    ) {}

    public function __toString(): string
    {
        return sprintf('%s:%d [%s] %s', $this->file, $this->line, $this->rule, $this->message);
    }

    /**
     * @return array{file: string, line: int, rule: string, message: string}
     */
    public function toArray(): array
    {
        return ['file' => $this->file, 'line' => $this->line, 'rule' => $this->rule, 'message' => $this->message];
    }
}
