<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Builds one log line as Watch expects it (POST /api/v1/logs). Port of watch_client's Entry.
 */
final class LogEntry
{
    public const LEVELS = Config::LEVELS;
    public const MAX_MESSAGE_BYTES = 32768;
    public const MAX_ENTRY_BYTES = 65536;
    public const MAX_FIELD_BYTES = 8192;
    public const MAX_CORE_CHARS = 255;
    /** Fields Watch stores in columns; everything else ends up in its payload. */
    public const RESERVED = ['timestamp', 'level', 'message', 'environment', 'host', 'request_id', 'user_id', 'job_id'];
    public const CORE = ['timestamp', 'level', 'message', 'environment', 'host', 'request_id', 'user_id', 'job_id', 'app_version'];

    /**
     * Watch's level name for a PSR-3 level, a Watch level or 0..5.
     */
    public static function levelName(mixed $level): string
    {
        if (is_int($level)) {
            return self::LEVELS[$level] ?? 'unknown';
        }
        if ($level instanceof \Stringable) {
            $level = (string) $level;
        }

        return is_string($level) ? Config::normalizeLevel($level) : 'info';
    }

    public static function levelIndex(mixed $level): int
    {
        $index = array_search(self::levelName($level), self::LEVELS, true);

        return is_int($index) ? $index : 1;
    }

    /**
     * PSR-3 placeholders: "{name}" is replaced by the (already filtered) context value when it
     * is a scalar.
     *
     * @param array<array-key, mixed> $fields
     */
    public static function interpolate(string $message, array $fields): string
    {
        if ($fields === [] || !str_contains($message, '{')) {
            return $message;
        }
        $replace = [];
        foreach ($fields as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $replace['{' . $key . '}'] = (string) $value;
            } elseif (is_bool($value)) {
                $replace['{' . $key . '}'] = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $replace['{' . $key . '}'] = 'null';
            }
        }

        return strtr($message, $replace);
    }

    /**
     * @param array<array-key, mixed> $context PSR-3 context: placeholders, structured fields, "exception"
     * @return array<string, mixed>
     */
    public static function build(
        Config $config,
        Filter $filter,
        mixed $level,
        string|\Stringable $message,
        array $context,
        Scope $scope,
        ?float $time = null,
    ): array {
        $fields = self::fields($config, $filter, $context);
        $entry = [
            'timestamp' => Time::iso($time ?? microtime(true), 6),
            'level' => self::levelName($level),
            'message' => Text::clean(self::interpolate((string) $message, $fields), self::MAX_MESSAGE_BYTES),
            'environment' => $config->environment,
            'host' => $config->host,
        ];
        if ($config->appVersion !== null) {
            $entry['app_version'] = $config->appVersion;
        }
        foreach (self::scopeFields($filter, $scope) as $key => $value) {
            if (!array_key_exists($key, $entry)) {
                $entry[$key] = $value;
            }
        }
        foreach ($fields as $key => $value) {
            $key = (string) $key;
            // Structured fields never overwrite the core columns.
            if (array_key_exists($key, $entry) && in_array($key, self::RESERVED, true)) {
                continue;
            }
            $entry[$key] = $value;
        }

        return self::limitSize(array_filter($entry, static fn ($value): bool => $value !== null));
    }

    /**
     * Keeps one entry under MAX_ENTRY_BYTES: core columns other than the message are cut at 255
     * characters, large fields become "[TRUNCATED: n bytes]", and if that is not enough all
     * extra fields are dropped.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public static function limitSize(array $entry): array
    {
        if (Json::bytes($entry) === null) {
            $core = [];
            foreach (self::CORE as $key) {
                if (isset($entry[$key]) && is_scalar($entry[$key])) {
                    $core[$key] = (string) $entry[$key];
                }
            }

            return $core + ['fields_unencodable' => true];
        }
        foreach (self::CORE as $key) {
            if ($key !== 'message' && isset($entry[$key]) && is_string($entry[$key])) {
                $entry[$key] = Text::cutChars($entry[$key], self::MAX_CORE_CHARS);
            }
        }
        if ((Json::bytes($entry) ?? 0) <= self::MAX_ENTRY_BYTES) {
            return $entry;
        }
        foreach (array_keys($entry) as $key) {
            if (in_array($key, self::CORE, true)) {
                continue;
            }
            $bytes = Json::bytes($entry[$key]) ?? 0;
            if ($bytes > self::MAX_FIELD_BYTES) {
                $entry[$key] = '[TRUNCATED: ' . $bytes . ' bytes]';
            }
        }
        if ((Json::bytes($entry) ?? 0) <= self::MAX_ENTRY_BYTES) {
            return $entry;
        }
        $core = ['fields_truncated' => true];
        foreach (self::CORE as $key) {
            if (array_key_exists($key, $entry)) {
                $core[$key] = $entry[$key];
            }
        }
        if ((Json::bytes($core) ?? 0) <= self::MAX_ENTRY_BYTES) {
            return $core;
        }
        $core['message'] = Text::cutChars((string) ($core['message'] ?? ''), 1024);

        return $core;
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    private static function fields(Config $config, Filter $filter, array $context): array
    {
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            unset($context['exception']);
        }
        $fields = Json::safe($context, $filter);
        $fields = is_array($fields) ? $fields : [];
        if ($exception instanceof \Throwable) {
            $fields['exception'] = [
                'class' => ErrorReport::className($exception),
                'message' => Text::clean($exception->getMessage(), 1024),
                'location' => Backtrace::relative($exception->getFile(), $config->root) . ':' . $exception->getLine(),
            ];
        }

        return $fields === [] ? [] : $filter->apply($fields);
    }

    /**
     * Request / job IDs and the Watch::context() fields (filtered, as scalars).
     *
     * @return array<string, mixed>
     */
    private static function scopeFields(Filter $filter, Scope $scope): array
    {
        $out = [];
        $context = Json::safe($scope->context, $filter);
        if (is_array($context) && $context !== []) {
            foreach ($filter->apply($context) as $key => $value) {
                $out[(string) $key] = is_scalar($value) || $value === null ? $value : Json::encode($value);
            }
        }
        $ids = [
            'request_id' => $scope->requestId,
            'job_id' => $scope->jobId,
            'job_class' => $scope->jobClass,
            'queue' => $scope->queue,
        ];
        if ($scope->requestId !== null && $scope->userId !== null) {
            $ids['user_id'] = $scope->userId;
        }
        foreach ($ids as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
