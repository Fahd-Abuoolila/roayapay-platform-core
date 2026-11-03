<?php

declare(strict_types=1);

namespace RoayaPay\PlatformCore\Logging;

use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;
use RoayaPay\PlatformCore\Monitoring\RequestContext;
use RoayaPay\PlatformCore\Support\Sanitizer;
use RoayaPay\PlatformCore\Transactions\TransactionContext;
use Throwable;

/**
 * Structured RoayaPay logger.
 *
 *     $logger->info('payment.created', ['payment_id' => $id]);
 *     $logger->security('login.blocked', ['user_id' => $id]);
 *
 * Every entry is sanitized (passwords, OTPs, tokens, card numbers, secrets are
 * redacted) and enriched with request_id and transaction_id. A logging failure
 * is swallowed: it never breaks the request being processed.
 */
final readonly class RoayaPayLogger
{
    public function __construct(
        private Container $container,
        private Sanitizer $sanitizer,
        private ?string $channel = null,
        private ?string $securityChannel = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function security(string $message, array $context = []): void
    {
        $this->log(LogLevel::SECURITY, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(LogLevel $level, string $message, array $context = [], ?string $channel = null): void
    {
        try {
            $channel ??= $level === LogLevel::SECURITY ? ($this->securityChannel ?? $this->channel) : $this->channel;

            $this->resolveLogger($channel)->log(
                $level->psrLevel(),
                $this->sanitizer->scrubString($message),
                $this->enrich($level, $this->sanitizer->sanitize($context)),
            );
        } catch (Throwable $failure) {
            $this->lastResort($level, $message, $failure);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function enrich(LogLevel $level, array $context): array
    {
        $request = $this->container->make(RequestContext::class);
        $transaction = $this->container->make(TransactionContext::class);

        return [
            ...$context,
            'roayapay' => [
                'level' => $level->value,
                'request_id' => $context['request_id'] ?? $request->requestId,
                'transaction_id' => $context['transaction_id'] ?? $transaction->transactionId,
            ],
        ];
    }

    private function resolveLogger(?string $channel): LoggerInterface
    {
        $manager = $this->container->make('log');

        return $channel === null ? $manager->channel() : $manager->channel($channel);
    }

    private function lastResort(LogLevel $level, string $message, Throwable $failure): void
    {
        try {
            error_log(sprintf(
                '[roayapay] logging failed (%s) while writing %s: %s',
                $failure::class,
                $level->value,
                $this->sanitizer->scrubString($message),
            ));
        } catch (Throwable) {
            // Never let logging break the request.
        }
    }
}
