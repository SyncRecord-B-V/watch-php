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
     * Longer field names are never copied, filtered or joined into a path: the whole field becomes
     * one KEY_TOO_LONG marker (a cut name could reveal part of a secret's name or value).
     */
    public const MAX_KEY_BYTES = 256;

    public const KEY_TOO_LONG = '[KEY TOO LONG]';

    /** Values copied at most (arrays, their items, object fields); the rest becomes "[TRUNCATED]". */
    public const MAX_NODES = 2000;

    /** Bytes of text copied at most, all strings together (strings are cut to what is left). */
    public const MAX_BYTES = 262144;

    /**
     * A bounded copy with only JSON-friendly values: finite numbers ("NAN"/"INF" as text), strings,
     * arrays, bounded depth, no object cycles. Objects become their JSON form, string form or
     * "[object Class]".
     *
     * Safe for any value an app passes in, including huge or self-referencing arrays: the copy stops
     * after MAX_NODES values, MAX_BYTES of text and MAX_DEPTH levels, so it never costs more than
     * that. With a filter, a sensitive key ("password", "token"...) becomes "[FILTERED]" before its
     * value is looked at.
     */
    public static function safe(mixed $value, ?Filter $filter = null): mixed
    {
        $budget = ['nodes' => self::MAX_NODES, 'bytes' => self::MAX_BYTES];

        return self::copy($value, 0, [], $budget, $filter, null, null);
    }

    /**
     * @param array<int, true> $path objects on the current path (cycle detection)
     * @param array{nodes: int, bytes: int} $budget shared by the whole copy
     */
    private static function copy(
        mixed $value,
        int $depth,
        array $path,
        array &$budget,
        ?Filter $filter,
        ?string $key,
        ?string $full,
    ): mixed {
        if ($budget['nodes'] <= 0) {
            return '[TRUNCATED]';
        }
        $budget['nodes']--;
        if ($depth > self::MAX_DEPTH) {
            return '[TOO DEEP]';
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            return self::text($value, $budget);
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }
        if (is_array($value)) {
            return self::copyArray($value, $depth, $path, $budget, $filter, $key, $full);
        }
        if (is_object($value)) {
            return self::object($value, $depth, $path, $budget, $filter, $key, $full);
        }

        return '[' . get_debug_type($value) . ']';
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<int, true> $path
     * @param array{nodes: int, bytes: int} $budget
     * @return array<array-key, mixed>
     */
    private static function copyArray(
        array $value,
        int $depth,
        array $path,
        array &$budget,
        ?Filter $filter,
        ?string $key,
        ?string $full,
    ): array {
        // Items of a list belong to the list's key (already checked); other keys are checked here.
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $itemKey => $item) {
            if ($budget['nodes'] <= 0 || $budget['bytes'] <= 0) {
                $out[$isList ? count($out) : '...'] = '[TRUNCATED]';
                break;
            }
            if ($isList && $key !== null) {
                $out[$itemKey] = self::copy($item, $depth + 1, $path, $budget, $filter, $key, $full);
                continue;
            }
            if (is_string($itemKey) && strlen($itemKey) > self::MAX_KEY_BYTES) {
                // Checked before anything copies the name (lowercasing, the dotted path, the output).
                $budget['nodes']--;
                $out[self::KEY_TOO_LONG] = '[TRUNCATED]';
                continue;
            }
            $name = (string) $itemKey;
            $budget['bytes'] -= strlen($name);
            $itemFull = $full === null ? $name : $full . '.' . $name;
            if ($filter !== null && $filter->matches($name, $itemFull)) {
                $budget['nodes']--;
                $out[$itemKey] = Filter::FILTERED;
                continue;
            }
            $out[$itemKey] = self::copy($item, $depth + 1, $path, $budget, $filter, $name, $itemFull);
        }

        return $out;
    }

    /**
     * @param array{nodes: int, bytes: int} $budget
     */
    private static function text(string $value, array &$budget): string
    {
        $length = strlen($value);
        if ($length <= $budget['bytes']) {
            $budget['bytes'] -= $length;

            return $value;
        }
        $cut = Text::cutBytes($value, max(0, $budget['bytes']));
        $budget['bytes'] = 0;

        return $cut . '...[TRUNCATED]';
    }

    /**
     * @param array<int, true> $path
     * @param array{nodes: int, bytes: int} $budget
     */
    private static function object(
        object $value,
        int $depth,
        array $path,
        array &$budget,
        ?Filter $filter,
        ?string $key,
        ?string $full,
    ): mixed {
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
                return self::text(ErrorReport::className($value) . ': ' . $value->getMessage(), $budget);
            }
            if ($value instanceof \JsonSerializable) {
                return self::copy($value->jsonSerialize(), $depth + 1, $path, $budget, $filter, $key, $full);
            }
            if ($value instanceof \Stringable) {
                return self::text((string) $value, $budget);
            }
            if ($value instanceof \stdClass) {
                return self::copyArray(get_object_vars($value), $depth + 1, $path, $budget, $filter, $key, $full);
            }

            return '[object ' . get_class($value) . ']';
        } catch (\Throwable) {
            return '[UNREADABLE]';
        }
    }
}
