<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Reads an outside API's quota from the headers of its answer (port of watch_client's ApiQuota):
 *
 *   RateLimit-Limit / -Remaining / -Reset            IETF draft, also "RateLimit: limit=…, remaining=…, reset=…"
 *   X-RateLimit-Limit / -Remaining / -Reset          GitHub and many others
 *   X-RateLimit-Minutely-Limit / -Remaining / -Reset Exact Online (per minute, next to the daily X-RateLimit-*)
 *   X-HubSpot-RateLimit-Daily / -Daily-Remaining     HubSpot (daily)
 *   Sforce-Limit-Info: api-usage=6247/140000         Salesforce (24 hours)
 *   X-Shopify-Shop-Api-Call-Limit: 32/40             Shopify (leaky bucket)
 *
 * Returns at most MAX quotas: [["name", "limit", "remaining", "reset_at" (ISO 8601 or null)]].
 * Headers longer than 256 bytes are ignored before any matching; numbers have bounded digits.
 */
final class ApiQuota
{
    public const MAX = 4;
    public const MAX_VALUE = 4611686018427387904; // 2**62
    public const MAX_HEADER_BYTES = 256;
    public const MAX_FIELDS = 8;

    private const NUMBER = '/\A\s*-?\d{1,19}(?:\.\d{1,6})?\s*\z/';
    private const FIELD = '/\A\s*([a-z]{1,9})\s*=\s*(\d{1,19})\s*\z/i';
    private const MAX_FUTURE = 400 * 86400;

    /**
     * @param mixed $headers name => value or name => list of values (PSR-7 style); any case
     * @param float|null $now Unix time (tests)
     * @return list<array{name: string, limit: int, remaining: int, reset_at: ?string}>
     */
    public static function parse(mixed $headers, ?float $now = null): array
    {
        if (!is_array($headers)) {
            return [];
        }
        try {
            $now ??= microtime(true);
            $h = self::normalize($headers);
            $exactOnline = self::header($h, 'x-ratelimit-minutely-remaining') !== null;
            $candidates = [
                self::standard($h, $now),
                self::combined($h, $now),
                self::prefixed($h, 'x-ratelimit-minutely', 'minute', $now),
                self::prefixed($h, 'x-ratelimit', $exactOnline ? 'day' : 'default', $now),
                self::hubspot($h),
                self::salesforce($h),
                self::shopify($h),
            ];
            $out = [];
            foreach ($candidates as $quota) {
                if ($quota !== null && !isset($out[$quota['name']])) {
                    $out[$quota['name']] = $quota;
                }
            }

            return array_slice(array_values($out), 0, self::MAX);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string>
     */
    private static function normalize(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, 'is_string'));
            }
            if (is_string($value)) {
                $out[strtolower($name)] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $h
     * @return array{name: string, limit: int, remaining: int, reset_at: ?string}|null
     */
    private static function standard(array $h, float $now): ?array
    {
        return self::build(
            'default',
            self::header($h, 'ratelimit-limit'),
            self::header($h, 'ratelimit-remaining'),
            self::header($h, 'ratelimit-reset'),
            $now,
            true,
        );
    }

    /**
     * "RateLimit: limit=100, remaining=50, reset=30" (one header).
     *
     * @param array<string, string> $h
     * @return array{name: string, limit: int, remaining: int, reset_at: ?string}|null
     */
    private static function combined(array $h, float $now): ?array
    {
        $value = self::header($h, 'ratelimit') ?? '';
        if ($value === '' || self::header($h, 'ratelimit-remaining') !== null) {
            return null;
        }
        $parts = preg_split('/[,;]/', $value, self::MAX_FIELDS + 1);
        $fields = [];
        foreach (array_slice(is_array($parts) ? $parts : [], 0, self::MAX_FIELDS) as $part) {
            if (preg_match(self::FIELD, $part, $match) === 1) {
                $key = strtolower($match[1]);
                if (!isset($fields[$key])) {
                    $fields[$key] = $match[2];
                }
            }
        }

        return self::build(
            'default',
            $fields['limit'] ?? null,
            $fields['remaining'] ?? $fields['r'] ?? null,
            $fields['reset'] ?? $fields['t'] ?? null,
            $now,
            true,
        );
    }

    /**
     * @param array<string, string> $h
     * @return array{name: string, limit: int, remaining: int, reset_at: ?string}|null
     */
    private static function prefixed(array $h, string $prefix, string $name, float $now): ?array
    {
        return self::build(
            $name,
            self::header($h, $prefix . '-limit'),
            self::header($h, $prefix . '-remaining'),
            self::header($h, $prefix . '-reset'),
            $now,
            false,
        );
    }

    /**
     * @param array<string, string> $h
     * @return array{name: string, limit: int, remaining: int, reset_at: ?string}|null
     */
    private static function hubspot(array $h): ?array
    {
        return self::build(
            'day',
            self::header($h, 'x-hubspot-ratelimit-daily'),
            self::header($h, 'x-hubspot-ratelimit-daily-remaining'),
            null,
            null,
            false,
        );
    }

    /**
     * "api-usage=6247/140000": used / limit over the last 24 hours.
     *
     * @param array<string, string> $h
     * @return array{name: string, limit: int, remaining: int, reset_at: ?string}|null
     */
    private static function salesforce(array $h): ?array
    {
        $value = self::header($h, 'sforce-limit-info') ?? '';
        if (preg_match('#api-usage=(\d{1,19})/(\d{1,19})#', $value, $match) !== 1) {
            return null;
        }
        $used = (int) $match[1];
        $limit = (int) $match[2];

        return self::build('day', $limit, max($limit - $used, 0), null, null, false);
    }

    /**
     * "32/40": calls in the bucket / bucket size.
     *
     * @param array<string, string> $h
     * @return array{name: string, limit: int, remaining: int, reset_at: ?string}|null
     */
    private static function shopify(array $h): ?array
    {
        $value = self::header($h, 'x-shopify-shop-api-call-limit') ?? '';
        if (preg_match('#\A\s*(\d{1,19})\s*/\s*(\d{1,19})\s*\z#', $value, $match) !== 1) {
            return null;
        }
        $limit = (int) $match[2];

        return self::build('bucket', $limit, max($limit - (int) $match[1], 0), null, null, false);
    }

    /**
     * @return array{name: string, limit: int, remaining: int, reset_at: ?string}|null
     */
    private static function build(string $name, mixed $limit, mixed $remaining, mixed $reset, ?float $now, bool $relative): ?array
    {
        if (!self::isNumber($limit) || !self::isNumber($remaining)) {
            return null;
        }
        $limitValue = floor((float) trim((string) $limit));
        $remainingValue = floor((float) trim((string) $remaining));
        $max = (float) self::MAX_VALUE;
        if (!($limitValue > 0) || $limitValue > $max || $remainingValue < -$max || $remainingValue > $max) {
            return null;
        }

        return [
            'name' => $name,
            'limit' => (int) $limitValue,
            'remaining' => (int) max(0.0, min($remainingValue, $limitValue)),
            'reset_at' => self::resetAt($reset, $now, $relative),
        ];
    }

    /**
     * Seconds from now (small numbers, or always for the IETF headers), a Unix time in seconds or
     * milliseconds, or an HTTP date.
     */
    private static function resetAt(mixed $value, ?float $now, bool $relative): ?string
    {
        if ($value === null || $now === null || !is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);
        if (preg_match(self::NUMBER, $text) === 1) {
            $number = (float) $text;
            if ($relative || $number < 10000000) {
                $time = $now + $number;
            } elseif ($number > 100000000000) {
                $time = $number / 1000;
            } else {
                $time = $number;
            }
        } else {
            $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $text, new \DateTimeZone('UTC'));
            if ($date === false) {
                return null;
            }
            $time = (float) $date->getTimestamp();
        }
        if ($time < $now - 60 || $time > $now + self::MAX_FUTURE) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', (int) floor($time));
    }

    /**
     * One header's value, or null when it is missing or too long to be a real quota.
     *
     * @param array<string, string> $h
     */
    private static function header(array $h, string $name): ?string
    {
        $value = $h[$name] ?? null;

        return $value !== null && strlen($value) <= self::MAX_HEADER_BYTES ? $value : null;
    }

    private static function isNumber(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        return is_string($value) && preg_match(self::NUMBER, $value) === 1;
    }
}
