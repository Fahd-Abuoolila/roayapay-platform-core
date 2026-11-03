<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Console;

use Illuminate\Console\Command;
use RoayaPay\PlatformCore\Analysis\ResponseContractScanner;
use RoayaPay\PlatformCore\Analysis\Violation;
use RoayaPay\PlatformCore\Codes\ApiCodeRegistry;

/**
 * php artisan roayapay:scan [paths...] [--json]
 *
 * Exits with 1 when violations are found (use it as a CI gate).
 */
final class ScanResponsesCommand extends Command
{
    protected $signature = 'roayapay:scan
        {paths?* : Files or directories with API code (default: app/Http/Controllers/Api)}
        {--json : Output violations as JSON}';

    protected $description = 'Statically check API code against the RoayaPay response standard';

    public function handle(ApiCodeRegistry $registry): int
    {
        $paths = $this->argument('paths') ?: [base_path('app/Http/Controllers/Api')];

        $violations = new ResponseContractScanner(array_keys($registry->all()))->scan($paths);

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(fn (Violation $v): array => $v->toArray(), $violations), JSON_PRETTY_PRINT));
        } else {
            foreach ($violations as $violation) {
                $this->error((string) $violation);
            }

            $violations === []
                ? $this->info('RoayaPay response standard: no violations.')
                : $this->error(sprintf('RoayaPay response standard: %d violation(s).', count($violations)));
        }

        return $violations === [] ? self::SUCCESS : self::FAILURE;
    }
}
