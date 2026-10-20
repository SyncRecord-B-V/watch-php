<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * @internal
 */
final class Time
{
    /**
     * UTC ISO 8601 with a fixed number of fraction digits, like Ruby's Time#iso8601(n):
     * 2026-10-20T12:34:56.123456Z (6), 2026-10-20T12:34:56.123Z (3), 2026-10-20T12:34:56Z (0).
     */
    public static function iso(float $time, int $precision = 6): string
    {
        $seconds = (int) floor($time);
        $base = gmdate('Y-m-d\TH:i:s', $seconds);
        if ($precision <= 0) {
            return $base . 'Z';
        }
        $scale = 10 ** $precision;
        $digits = (int) round(($time - $seconds) * $scale);
        if ($digits >= $scale) {
            $digits = $scale - 1;
        }

        return $base . '.' . str_pad((string) $digits, $precision, '0', STR_PAD_LEFT) . 'Z';
    }
}
