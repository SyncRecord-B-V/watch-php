<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * PSR-3 style methods without needing psr/log. With psr/log installed, Watch::logger() returns
 * Adapter\Psr3Logger instead (a real Psr\Log\LoggerInterface).
 */
class Logger
{
    /**
     * @param array<array-key, mixed> $context
     */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        Watch::log('emergency', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function alert(string|\Stringable $message, array $context = []): void
    {
        Watch::log('alert', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function critical(string|\Stringable $message, array $context = []): void
    {
        Watch::log('critical', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function error(string|\Stringable $message, array $context = []): void
    {
        Watch::log('error', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function warning(string|\Stringable $message, array $context = []): void
    {
        Watch::log('warning', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function notice(string|\Stringable $message, array $context = []): void
    {
        Watch::log('notice', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function info(string|\Stringable $message, array $context = []): void
    {
        Watch::log('info', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        Watch::log('debug', $message, $context);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        Watch::log(is_int($level) || is_string($level) ? $level : 'info', $message, $context);
    }
}
