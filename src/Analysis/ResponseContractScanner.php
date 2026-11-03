<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Analysis;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static checker for the RoayaPay response standard (CI enforcement).
 *
 * Point it at your API code (e.g. app/Http/Controllers/Api). It parses PHP
 * tokens (no code is executed, no extra dependency) and reports:
 *
 *  - response-json          response()->json(...), response()->jsonp(...), Response::json(...)
 *  - new-json-response      new JsonResponse(...), JsonResponse::fromJsonString(...)
 *  - numeric-api-code       setCode(1000), RoayaPayException::business(4001), 'code' => 4001
 *  - unregistered-api-code  setCode('NOPE') / RoayaPayException::x('NOPE') not in the registry
 *  - raw-array-return       `return [...]` / `return compact(...)` from public controller actions
 *
 * Suppress a single line with a `// roayapay-ignore: <reason>` comment, or a
 * whole action/controller with #[AllowsNonStandardResponse(reason: '...')].
 */
final class ResponseContractScanner
{
    private const array CODE_FACTORIES = [
        'business', 'payment', 'notfound', 'unauthorized', 'forbidden', 'external', 'internal', 'database', 'security', 'system',
    ];

    /**
     * @param  list<string>|null  $registeredCodes  Symbolic keys; null disables the unregistered-code rule.
     */
    public function __construct(private readonly ?array $registeredCodes = null) {}

    /**
     * @param  list<string>  $paths  Files or directories.
     * @return list<Violation>
     */
    public function scan(array $paths): array
    {
        $violations = [];

        foreach ($paths as $path) {
            foreach ($this->phpFiles($path) as $file) {
                array_push($violations, ...$this->scanSource((string) file_get_contents($file), $file));
            }
        }

        return $violations;
    }

    /**
     * @return list<Violation>
     */
    public function scanSource(string $source, string $file = 'source.php'): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE]),
        ));

        $ignoredLines = $this->ignoredLines($tokens);
        $violations = [];

        /** @var list<array{type: string, name: ?string, exempt: bool, public: bool, static: bool}> $scopes */
        $scopes = [];
        $pending = null;           // scope to open at the next "{"
        $pendingExempt = false;    // #[AllowsNonStandardResponse] seen
        $modifiers = [];

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // ---------------------------------------------------------------- attributes
            if ($token->is(T_ATTRIBUTE)) {
                $depth = 1;
                for ($j = $i + 1; $j < $count && $depth > 0; $j++) {
                    if ($tokens[$j]->text === '[') {
                        $depth++;
                    } elseif ($tokens[$j]->text === ']') {
                        $depth--;
                    } elseif (str_ends_with(ltrim($tokens[$j]->text, '\\'), 'AllowsNonStandardResponse')) {
                        $pendingExempt = true;
                    }
                }
                $i = $j - 1;

                continue;
            }

            // ---------------------------------------------------------------- scope tracking
            if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY])) {
                $modifiers[] = strtolower($token->text);

                continue;
            }

            if ($token->is([T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE]) && ! $this->prevIs($tokens, $i, T_DOUBLE_COLON)) {
                $name = $tokens[$i + 1] ?? null;
                $pending = [
                    'type' => 'class',
                    'name' => $name !== null && $name->is(T_STRING) ? $name->text : null,
                    'exempt' => $pendingExempt,
                    'public' => true,
                    'static' => false,
                ];
                $pendingExempt = false;
                $modifiers = [];

                continue;
            }

            if ($token->is(T_FUNCTION)) {
                $next = $tokens[$i + 1] ?? null;
                $named = $next !== null && ($next->is(T_STRING) || ($next->text === '&' && ($tokens[$i + 2] ?? null)?->is(T_STRING)));
                $pending = [
                    'type' => $named ? 'function' : 'closure',
                    'name' => $named ? ($next->is(T_STRING) ? $next->text : $tokens[$i + 2]->text) : null,
                    'exempt' => $pendingExempt,
                    'public' => ! in_array('private', $modifiers, true) && ! in_array('protected', $modifiers, true),
                    'static' => in_array('static', $modifiers, true),
                ];
                $pendingExempt = false;
                $modifiers = [];

                continue;
            }

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $scopes[] = $token->text === '{' && $pending !== null
                    ? $pending
                    : ['type' => 'block', 'name' => null, 'exempt' => false, 'public' => false, 'static' => false];
                $pending = null;
                $modifiers = [];

                continue;
            }

            if ($token->text === '}') {
                array_pop($scopes);

                continue;
            }

            if ($token->text === ';') {
                // Abstract/interface methods have no body.
                if ($pending !== null && $pending['type'] === 'function') {
                    $pending = null;
                }
                $modifiers = [];

                continue;
            }

            if (isset($ignoredLines[$token->line])) {
                continue;
            }

            $exempt = array_any($scopes, fn (array $s): bool => $s['exempt']);

            // ---------------------------------------------------------------- rules
            if (! $exempt) {
                $this->checkResponseJson($tokens, $i, $file, $violations);
                $this->checkNewJsonResponse($tokens, $i, $file, $violations);
                $this->checkRawArrayReturn($tokens, $i, $scopes, $file, $violations);
            }

            $this->checkCodes($tokens, $i, $file, $violations);
        }

        return $violations;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<Violation>  $violations
     */
    private function checkResponseJson(array $tokens, int $i, string $file, array &$violations): void
    {
        $t = $tokens[$i];

        // response()->json( / response()->jsonp(
        if ($t->is(T_STRING) && strtolower($t->text) === 'response'
            && ($tokens[$i + 1] ?? null)?->text === '(' && ($tokens[$i + 2] ?? null)?->text === ')'
            && ($tokens[$i + 3] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
            && in_array(strtolower((string) ($tokens[$i + 4] ?? null)?->text), ['json', 'jsonp'], true)
            && ! $this->prevIs($tokens, $i, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION])) {
            $violations[] = new Violation($file, $t->line, Violation::RESPONSE_JSON, 'Use BuildResponse::setCode(...)->getResponse() instead of response()->json().');
        }

        // Response::json( (facade)
        if ($t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            && in_array(strtolower(ltrim($t->text, '\\')), ['response', 'illuminate\support\facades\response'], true)
            && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)
            && in_array(strtolower((string) ($tokens[$i + 2] ?? null)?->text), ['json', 'jsonp'], true)) {
            $violations[] = new Violation($file, $t->line, Violation::RESPONSE_JSON, 'Use BuildResponse instead of Response::json().');
        }
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<Violation>  $violations
     */
    private function checkNewJsonResponse(array $tokens, int $i, string $file, array &$violations): void
    {
        $t = $tokens[$i];
        $next = $tokens[$i + 1] ?? null;

        if ($t->is(T_NEW) && $next !== null && $next->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            && $this->isForeignJsonResponse($next->text)) {
            $violations[] = new Violation($file, $t->line, Violation::NEW_JSON_RESPONSE, sprintf('Direct "new %s" is not allowed in API code; use BuildResponse.', $next->text));
        }

        if ($t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $this->isForeignJsonResponse($t->text)
            && ($next?->is(T_DOUBLE_COLON) ?? false)
            && in_array(strtolower((string) ($tokens[$i + 2] ?? null)?->text), ['fromjsonstring', 'create'], true)) {
            $violations[] = new Violation($file, $t->line, Violation::NEW_JSON_RESPONSE, sprintf('%s::%s() is not allowed in API code; use BuildResponse.', $t->text, $tokens[$i + 2]->text));
        }
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<array{type: string, name: ?string, exempt: bool, public: bool, static: bool}>  $scopes
     * @param  list<Violation>  $violations
     */
    private function checkRawArrayReturn(array $tokens, int $i, array $scopes, string $file, array &$violations): void
    {
        if (! $tokens[$i]->is(T_RETURN)) {
            return;
        }

        $function = null;
        $class = null;

        for ($s = count($scopes) - 1; $s >= 0; $s--) {
            if ($scopes[$s]['type'] === 'closure') {
                return; // returns inside closures are not controller results
            }
            if ($function === null && $scopes[$s]['type'] === 'function') {
                $function = $scopes[$s];
            } elseif ($function !== null && $scopes[$s]['type'] === 'class') {
                $class = $scopes[$s];
                break;
            }
        }

        if ($function === null || $class === null || ! $function['public'] || $function['static']
            || ! str_ends_with((string) $class['name'], 'Controller') || str_starts_with((string) $function['name'], '__')) {
            return;
        }

        $next = $tokens[$i + 1] ?? null;

        $isArray = $next !== null && (
            $next->text === '['
            || ($next->is(T_ARRAY) && ($tokens[$i + 2] ?? null)?->text === '(')
            || ($next->is(T_STRING) && in_array(strtolower($next->text), ['compact'], true) && ($tokens[$i + 2] ?? null)?->text === '(')
        );

        if ($isArray) {
            $violations[] = new Violation($file, $tokens[$i]->line, Violation::RAW_ARRAY_RETURN, sprintf(
                'Controller action %s::%s() returns a raw array; return BuildResponse::setCode(...)->getResponse().',
                $class['name'],
                $function['name'],
            ));
        }
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<Violation>  $violations
     */
    private function checkCodes(array $tokens, int $i, string $file, array &$violations): void
    {
        $t = $tokens[$i];
        $argIndex = null;

        // BuildResponse::setCode( / ->setCode(
        if ($t->is(T_STRING) && strtolower($t->text) === 'setcode' && ($tokens[$i + 1] ?? null)?->text === '(') {
            $argIndex = $i + 2;
        }

        // RoayaPayException::business( etc.
        if ($t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && str_ends_with($t->text, 'RoayaPayException')
            && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)
            && in_array(strtolower((string) ($tokens[$i + 2] ?? null)?->text), self::CODE_FACTORIES, true)
            && ($tokens[$i + 3] ?? null)?->text === '(') {
            $argIndex = $i + 4;
        }

        // new RoayaPayException(
        if ($t->is(T_NEW) && ($tokens[$i + 1] ?? null) !== null && str_ends_with($tokens[$i + 1]->text, 'RoayaPayException')
            && ($tokens[$i + 2] ?? null)?->text === '(') {
            $argIndex = $i + 3;
        }

        if ($argIndex !== null && ($arg = $tokens[$argIndex] ?? null) !== null) {
            $closesAfter = in_array(($tokens[$argIndex + 1] ?? null)?->text, [')', ','], true);

            if ($arg->is(T_LNUMBER) && $closesAfter) {
                $violations[] = new Violation($file, $arg->line, Violation::NUMERIC_CODE, sprintf('Numeric API code %s: use the symbolic name from config/roayapay_api_codes.php.', $arg->text));
            } elseif ($arg->is(T_CONSTANT_ENCAPSED_STRING) && $closesAfter && $this->registeredCodes !== null) {
                $code = substr($arg->text, 1, -1);
                if (! in_array($code, $this->registeredCodes, true)) {
                    $violations[] = new Violation($file, $arg->line, Violation::UNKNOWN_CODE, sprintf('API code "%s" is not registered in config/roayapay_api_codes.php.', $code));
                }
            }
        }

        // Hard-coded response arrays: 'code' => 4001
        if ($t->is(T_CONSTANT_ENCAPSED_STRING) && in_array($t->text, ["'code'", '"code"'], true)
            && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_ARROW) && ($tokens[$i + 2] ?? null)?->is(T_LNUMBER)) {
            $violations[] = new Violation($file, $t->line, Violation::NUMERIC_CODE, sprintf("Hard-coded response code %s: build responses with BuildResponse and symbolic codes.", $tokens[$i + 2]->text));
        }
    }

    private function isForeignJsonResponse(string $name): bool
    {
        $short = substr((string) strrchr('\\'.$name, '\\'), 1);

        return str_ends_with($short, 'JsonResponse') && $short !== 'RoayaPayJsonResponse';
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  int|list<int>  $kinds
     */
    private function prevIs(array $tokens, int $i, int|array $kinds): bool
    {
        return ($tokens[$i - 1] ?? null)?->is($kinds) ?? false;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array<int, true>
     */
    private function ignoredLines(array $tokens): array
    {
        $lines = [];

        foreach ($tokens as $token) {
            if ($token->is(T_COMMENT) && str_contains($token->text, 'roayapay-ignore')) {
                $lines[$token->line] = true;
            }
        }

        return $lines;
    }

    /**
     * @return iterable<string>
     */
    private function phpFiles(string $path): iterable
    {
        if (is_file($path)) {
            yield $path;

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
