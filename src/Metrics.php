<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Performance numbers added up per minute (POST /api/v1/metrics):
 *
 *   requests  per endpoint: count, 5xx errors, 4xx client errors, total / slowest time, time in the
 *             database and outgoing HTTP, number of queries, response-time histogram
 *   http      per outside host: count, 5xx (or failed), 4xx, 429s, failures, timeouts, time,
 *             histogram and the latest API quota per name
 *   jobs      per job class: runs, failures, time, histogram, time in the database and HTTP
 *   job_http  outgoing calls per job class and host
 *
 * Rows of the same name are added up exactly like the hub does, so partial reports from many
 * processes (or a spool of many requests) give the same totals.
 */
final class Metrics
{
    public const MAX_ENDPOINTS = 300;
    public const MAX_HOSTS = 100;
    public const MAX_JOB_CLASSES = 200;
    public const MAX_JOB_HOSTS = 500;

    public const KINDS = ['requests', 'http', 'jobs', 'job_http'];

    private const LIMITS = [
        'requests' => self::MAX_ENDPOINTS,
        'http' => self::MAX_HOSTS,
        'jobs' => self::MAX_JOB_CLASSES,
        'job_http' => self::MAX_JOB_HOSTS,
    ];
    private const INT_MAX = 2147483647;
    private const NAME_KEYS = ['endpoint', 'host', 'job_class'];
    private const INT_KEYS = ['count', 'errors', 'client_errors', 'failures', 'timeouts', 'rate_limited', 'queries'];
    private const FLOAT_KEYS = ['total_ms', 'db_ms', 'view_ms', 'http_ms', 'wait_ms'];
    private const MAX_KEYS = ['max_ms', 'wait_max_ms'];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $tables = ['requests' => [], 'http' => [], 'jobs' => [], 'job_http' => []];

    public function recordRequest(
        string $endpoint,
        float $ms,
        int $status = 200,
        float $dbMs = 0.0,
        float $viewMs = 0.0,
        float $httpMs = 0.0,
        int $queries = 0,
    ): void {
        $ms = self::ms($ms);
        $this->merge('requests', [
            'endpoint' => $endpoint,
            'count' => 1,
            'errors' => $status >= 500 ? 1 : 0,
            'client_errors' => $status >= 400 && $status < 500 ? 1 : 0,
            'total_ms' => $ms,
            'max_ms' => $ms,
            'db_ms' => self::ms($dbMs),
            'view_ms' => self::ms($viewMs),
            'http_ms' => self::ms($httpMs),
            'queries' => max(0, $queries),
            'buckets' => Histogram::single($ms),
        ]);
    }

    /**
     * @param string|null $failure "timeout" or "connection" when the call failed without an answer
     * @param list<array<string, mixed>> $quotas from ApiQuota::parse()
     */
    public function recordHttp(
        string $host,
        float $ms,
        ?int $status = null,
        ?string $failure = null,
        array $quotas = [],
        ?string $jobClass = null,
    ): void {
        $ms = self::ms($ms);
        $code = $status ?? 0;
        $error = $code >= 500 || $failure !== null ? 1 : 0;
        $limited = $code === 429 ? 1 : 0;
        $this->merge('http', [
            'host' => $host,
            'count' => 1,
            'errors' => $error,
            'client_errors' => $code >= 400 && $code < 500 ? 1 : 0,
            'rate_limited' => $limited,
            'failures' => $failure !== null ? 1 : 0,
            'timeouts' => $failure === 'timeout' ? 1 : 0,
            'total_ms' => $ms,
            'max_ms' => $ms,
            'buckets' => Histogram::single($ms),
            'quotas' => array_values($quotas),
        ]);
        if ($jobClass !== null && $jobClass !== '') {
            $this->merge('job_http', [
                'job_class' => $jobClass,
                'host' => $host,
                'count' => 1,
                'errors' => $error,
                'rate_limited' => $limited,
                'total_ms' => $ms,
            ]);
        }
    }

    public function recordJob(
        string $jobClass,
        float $ms,
        bool $failed = false,
        ?string $queue = null,
        ?float $waitMs = null,
        float $dbMs = 0.0,
        int $queries = 0,
        float $httpMs = 0.0,
    ): void {
        $ms = self::ms($ms);
        $row = [
            'job_class' => $jobClass,
            'count' => 1,
            'errors' => $failed ? 1 : 0,
            'total_ms' => $ms,
            'max_ms' => $ms,
            'buckets' => Histogram::single($ms),
            'db_ms' => self::ms($dbMs),
            'http_ms' => self::ms($httpMs),
            'queries' => max(0, $queries),
        ];
        if ($queue !== null && $queue !== '') {
            $row['queue'] = $queue;
        }
        if ($waitMs !== null) {
            $row['wait_ms'] = self::ms($waitMs);
            $row['wait_max_ms'] = self::ms($waitMs);
        }
        $this->merge('jobs', $row);
    }

    /**
     * Adds the rows of a payload (or of rows()) to these numbers.
     *
     * @param array<mixed> $payload
     */
    public function absorb(array $payload): void
    {
        foreach (self::KINDS as $kind) {
            $rows = $payload[$kind] ?? null;
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $this->merge($kind, $row);
                }
            }
        }
    }

    public function isEmpty(): bool
    {
        foreach ($this->tables as $rows) {
            if ($rows !== []) {
                return false;
            }
        }

        return true;
    }

    public function reset(): void
    {
        $this->tables = ['requests' => [], 'http' => [], 'jobs' => [], 'job_http' => []];
    }

    /**
     * The rows per kind, sorted by name, milliseconds rounded to 1 decimal.
     *
     * @return array{requests: list<array<string, mixed>>, http: list<array<string, mixed>>, jobs: list<array<string, mixed>>, job_http: list<array<string, mixed>>}
     */
    public function rows(): array
    {
        $out = ['requests' => [], 'http' => [], 'jobs' => [], 'job_http' => []];
        foreach (self::KINDS as $kind) {
            $table = $this->tables[$kind];
            ksort($table, SORT_STRING);
            foreach ($table as $row) {
                $out[$kind][] = self::finish($row);
            }
        }

        return $out;
    }

    /**
     * The body for POST /api/v1/metrics.
     *
     * @param int|null $sampledAt Unix time of the minute the numbers belong to (default: now)
     * @return array<string, mixed>
     */
    public function payload(Config $config, ?int $sampledAt = null): array
    {
        $pid = getmypid();
        $head = [
            'sampled_at' => gmdate('Y-m-d\TH:i:s\Z', $sampledAt ?? time()),
            'host' => $config->host,
            'pid' => is_int($pid) ? $pid : 0,
            'environment' => $config->environment,
        ];
        if ($config->appVersion !== null) {
            $head['version'] = $config->appVersion;
        }

        return $head + $this->rows();
    }

    /**
     * @param array<mixed> $row
     */
    private function merge(string $kind, array $row): void
    {
        $name = self::nameOf($kind, $row);
        if ($name === null || self::int($row['count'] ?? 0) <= 0) {
            return;
        }
        if (!isset($this->tables[$kind][$name])) {
            if (count($this->tables[$kind]) >= self::LIMITS[$kind]) {
                return;
            }
            $this->tables[$kind][$name] = self::combine([], $row);

            return;
        }
        $this->tables[$kind][$name] = self::combine($this->tables[$kind][$name], $row);
    }

    /**
     * @param array<mixed> $row
     */
    private static function nameOf(string $kind, array $row): ?string
    {
        $key = match ($kind) {
            'requests' => 'endpoint',
            'http' => 'host',
            default => 'job_class',
        };
        $name = $row[$key] ?? null;
        if (!is_string($name) || $name === '') {
            return null;
        }
        if ($kind !== 'job_http') {
            return $name;
        }
        $host = $row['host'] ?? null;

        return is_string($host) && $host !== '' ? $name . "\0" . $host : null;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<mixed> $new
     * @return array<string, mixed>
     */
    private static function combine(array $old, array $new): array
    {
        foreach ($new as $key => $value) {
            if (in_array($key, self::NAME_KEYS, true)) {
                if (is_string($value)) {
                    $old[$key] = $value;
                }
            } elseif ($key === 'queue') {
                if (is_string($value) && $value !== '') {
                    $old[$key] = $value;
                }
            } elseif ($key === 'buckets') {
                $old[$key] = Histogram::add(
                    is_array($old[$key] ?? null) ? $old[$key] : Histogram::empty(),
                    is_array($value) ? $value : [],
                );
            } elseif ($key === 'quotas') {
                $old[$key] = self::mergeQuotas(is_array($old[$key] ?? null) ? $old[$key] : [], is_array($value) ? $value : []);
            } elseif (in_array($key, self::MAX_KEYS, true)) {
                $old[$key] = max((float) ($old[$key] ?? 0.0), self::num($value));
            } elseif (in_array($key, self::INT_KEYS, true)) {
                $old[$key] = min(self::INT_MAX, self::int($old[$key] ?? 0) + self::int($value));
            } elseif (in_array($key, self::FLOAT_KEYS, true)) {
                $old[$key] = (float) ($old[$key] ?? 0.0) + self::num($value);
            }
        }

        return $old;
    }

    /**
     * The latest quota per name wins.
     *
     * @param array<mixed> $old
     * @param array<mixed> $new
     * @return list<array<mixed>>
     */
    private static function mergeQuotas(array $old, array $new): array
    {
        $byName = [];
        foreach (array_merge(array_values($old), array_values($new)) as $quota) {
            if (is_array($quota) && isset($quota['name']) && is_string($quota['name'])) {
                $byName[$quota['name']] = $quota;
            }
        }

        return array_values($byName);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function finish(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_float($value)) {
                $row[$key] = round($value, 1);
            }
        }

        return $row;
    }

    private static function ms(float $ms): float
    {
        return is_finite($ms) && $ms > 0 ? $ms : 0.0;
    }

    private static function int(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, min(self::INT_MAX, $value));
        }
        if (is_float($value) && is_finite($value)) {
            return (int) max(0.0, min((float) self::INT_MAX, $value));
        }
        if (is_string($value) && is_numeric($value)) {
            return self::int((float) $value);
        }

        return 0;
    }

    private static function num(mixed $value): float
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $number = (float) $value;

            return is_finite($number) && $number > 0 ? $number : 0.0;
        }

        return 0.0;
    }
}
