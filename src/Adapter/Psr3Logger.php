<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Adapter;

use Psr\Log\AbstractLogger;
use SyncRecord\Watch\Watch;

/**
 * A Psr\Log\LoggerInterface that sends to Watch (needs psr/log 1, 2 or 3). Use it as a Monolog
 * PSR handler target, a Laravel "custom" channel, or anywhere a PSR-3 logger is expected.
 *
 * Only load this class when psr/log is installed (Watch::logger() checks for you).
 */
final class Psr3Logger extends AbstractLogger
{
    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<array-key, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        if (!is_string($level) && !is_int($level)) {
            $level = 'info';
        }
        if (!is_string($message) && !$message instanceof \Stringable) {
            $message = is_scalar($message) ? (string) $message : get_debug_type($message);
        }
        Watch::log($level, $message, $context);
    }
}
