<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Where watch-php reports its own problems: PHP's error_log (never the app's output or logger),
 * at most once a minute per kind of message.
 *
 * @internal
 */
final class Diagnostics
{
    private static bool $enabled = true;

    /** @var array<string, float> */
    private static array $last = [];

    public static function enable(bool $enabled): void
    {
        self::$enabled = $enabled;
    }

    public static function log(string $message): void
    {
        if (!self::$enabled) {
            return;
        }
        try {
            $key = substr($message, 0, 40);
            $now = microtime(true);
            if (isset(self::$last[$key]) && $now - self::$last[$key] < 60.0) {
                return;
            }
            if (count(self::$last) > 100) {
                self::$last = [];
            }
            self::$last[$key] = $now;
            @error_log('[watch-php] ' . $message);
        } catch (\Throwable) {
            // Nothing else to do.
        }
    }
}
