<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * JSON helpers: a copy of any value that json_encode() can always encode, and encoding that
 * never throws.
 *
 * @internal
 */
final class Json
{
    public const FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** Nesting deeper than this becomes "[TOO DEEP]". */
    public const MAX_DEPTH = 20;

    /**
     * The JSON text, or null when the value can't be encoded.
     */
    public static function encode(mixed $value): ?string
    {
        try {
            $json = json_encode($value, self::FLAGS);
        } catch (\Throwable) {
            return null;
        }

        return is_string($json) ? $json : null;
    }

    /**
     * Size of the value as JSON in bytes, or null when it can't be encoded.
     */
    public static function bytes(mixed $value): ?int
    {
        $json = self::encode($value);

        return $json === null ? null : strlen($json);
    }

    /**
     * A copy with only JSON-friendly values: finite numbers ("NAN"/"INF" as text), strings,
     * arrays, bounded depth, no object cycles. Objects become their JSON form, string form or
     * "[object Class]".
     *
     * @param array<int, true> $path objects on the current path (cycle detection)
     */
    public static function safe(mixed $value, int $depth = 0, array $path = []): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[TOO DEEP]';
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::safe($item, $depth + 1, $path);
            }

            return $out;
        }
        if (is_object($value)) {
            return self::object($value, $depth, $path);
        }

        return '[' . get_debug_type($value) . ']';
    }

    /**
     * @param array<int, true> $path
     */
    private static function object(object $value, int $depth, array $path): mixed
    {
        $id = spl_object_id($value);
        if (isset($path[$id])) {
            return '[CIRCULAR]';
        }
        $path[$id] = true;

        try {
            if ($value instanceof \UnitEnum) {
                return $value instanceof \BackedEnum ? $value->value : $value->name;
            }
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d\TH:i:s.uP');
            }
            if ($value instanceof \Throwable) {
                return ErrorReport::className($value) . ': ' . $value->getMessage();
            }
            if ($value instanceof \JsonSerializable) {
                return self::safe($value->jsonSerialize(), $depth + 1, $path);
            }
            if ($value instanceof \Stringable) {
                return (string) $value;
            }
            if ($value instanceof \stdClass) {
                return self::safe(get_object_vars($value), $depth + 1, $path);
            }

            return '[object ' . get_class($value) . ']';
        } catch (\Throwable) {
            return '[UNREADABLE]';
        }
    }
}
