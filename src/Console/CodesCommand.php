<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Console;

use Illuminate\Console\Command;
use RoayaPay\PlatformCore\Codes\InvalidApiCodeRegistryException;
use RoayaPay\PlatformCore\Codes\RegistryValidator;

/**
 * php artisan roayapay:codes [--validate]
 *
 * Lists the registered API codes, or validates the registry (exit 1 on errors).
 */
final class CodesCommand extends Command
{
    protected $signature = 'roayapay:codes {--validate : Only validate the registry}';

    protected $description = 'List or validate the RoayaPay API code registry';

    public function handle(): int
    {
        $config = (array) config('roayapay_api_codes', []);
        $languages = array_values((array) config('roayapay.language.supported', ['ar', 'en']));

        $errors = new RegistryValidator()->validate($config, $languages);

        if ($errors !== []) {
            $this->error((string) new InvalidApiCodeRegistryException($errors)->getMessage());

            return self::FAILURE;
        }

        if ($this->option('validate')) {
            $this->info(sprintf('RoayaPay API code registry is valid (%d codes).', count($config)));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($config as $key => $definition) {
            $completed = $definition['process_completed'] ?? null;
            $rows[] = [
                $key,
                $definition['code'],
                $definition['http'],
                $definition['status'] ? 'true' : 'false',
                $completed === null ? 'unknown' : ($completed ? 'true' : 'false'),
                $definition['message']['en'] ?? '',
            ];
        }

        $this->table(['Symbol', 'Code', 'HTTP', 'Status', 'Completed', 'Message (en)'], $rows);

        return self::SUCCESS;
    }
}
