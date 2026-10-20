<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Response-time histogram with the same fixed bounds (milliseconds) as Watch and watch_client,
 * so histograms from many processes and minutes add up. Bucket i counts values <= BOUNDS[i];
 * the last bucket counts everything slower than 20 s.
 */
final class Histogram
{
    public const BOUNDS = [5, 10, 25, 50, 75, 100, 150, 200, 300, 500, 750, 1000, 1500, 2000, 3000, 5000, 7500, 10000, 20000];
    public const SIZE = 20;

    private const INT_MAX = 2147483647;

    public static function index(float $ms): int
    {
        foreach (self::BOUNDS as $index => $bound) {
            if ($ms <= $bound) {
                return $index;
            }
        }

        return self::SIZE - 1;
    }

    /**
     * @return list<int>
     */
    public static function empty(): array
    {
        return array_fill(0, self::SIZE, 0);
    }

    /**
     * @return list<int>
     */
    public static function single(float $ms): array
    {
        $buckets = self::empty();
        $buckets[self::index($ms)] = 1;

        return $buckets;
    }

    /**
     * Element-wise sum, exactly 20 non-negative integers, capped like the hub.
     *
     * @param array<mixed> $a
     * @param array<mixed> $b
     * @return list<int>
     */
    public static function add(array $a, array $b): array
    {
        $a = array_values($a);
        $b = array_values($b);
        $out = [];
        for ($i = 0; $i < self::SIZE; $i++) {
            $out[] = min(self::INT_MAX, self::count($a[$i] ?? 0) + self::count($b[$i] ?? 0));
        }

        return $out;
    }

    private static function count(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, min(self::INT_MAX, $value));
        }
        if (is_float($value) && is_finite($value)) {
            return (int) max(0.0, min((float) self::INT_MAX, $value));
        }
        if (is_string($value) && is_numeric($value)) {
            return self::count((float) $value);
        }

        return 0;
    }
}
