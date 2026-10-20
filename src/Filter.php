<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Hides secrets and personal data before anything leaves the app: log fields, error context,
 * params, headers, breadcrumbs and URLs. Port of watch_client's Filter.
 *
 * A rule is a case-insensitive substring of the key ("passw" masks "password_confirmation").
 * A rule with a dot ("account.pin") matches the dotted path of nested keys instead.
 */
final class Filter
{
    public const FILTERED = '[FILTERED]';

    /** Query values that are masked in URLs on top of the rules: secrets and personal data. */
    public const URL_SECRET = '/token|secret|passw|key|auth|session|signature|code|e?mail|name|phone|tel|address|iban|zip|postcode|birth/i';

    public const MAX_DEPTH = 32;
    public const MAX_QUERY_BYTES = 4096;
    public const MAX_QUERY_PARAMS = 100;
    public const MAX_PARAM_DEPTH = 8;

    private const PARAM_NAME = '/\A([^\[\]]+)((?:\[[^\[\]]*\])*)\z/';

    /** @var list<string> */
    private array $rules;

    /**
     * @param list<string> $rules
     */
    public function __construct(array $rules = Config::DEFAULT_FILTER_KEYS)
    {
        $clean = [];
        foreach ($rules as $rule) {
            if (is_string($rule) && trim($rule) !== '') {
                $clean[] = strtolower(trim($rule));
            }
        }
        $this->rules = array_values(array_unique($clean));
    }

    /**
     * A filtered copy, every level deep. Items of a list inherit the key of the list.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function apply(array $data): array
    {
        try {
            return $this->map($data, null, null, 0);
        } catch (\Throwable) {
            // Never send unfiltered data (or an oversized name) because a rule failed.
            $out = [];
            foreach ($data as $key => $_) {
                $out[is_string($key) && strlen($key) > Json::MAX_KEY_BYTES ? Json::KEY_TOO_LONG : $key] = self::FILTERED;
            }

            return $out;
        }
    }

    /**
     * Whether a key (e.g. a query string name) is covered by the rules.
     */
    public function matches(string $name, ?string $full = null): bool
    {
        $lowerName = strtolower($name);
        $lowerFull = $full === null ? null : strtolower($full);
        foreach ($this->rules as $rule) {
            if (str_contains($rule, '.')) {
                if ($lowerFull !== null && str_contains($lowerFull, $rule)) {
                    return true;
                }
            } elseif (str_contains($lowerName, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The URL with secret / personal query values masked and the fragment removed. A query that
     * is too long, too deep or has too many parameters is left out.
     */
    public function url(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            $parts = explode('?', $value, 2);
            $base = explode('#', $parts[0], 2)[0];
            if (count($parts) < 2) {
                return $base;
            }
            $filtered = $this->queryString(explode('#', $parts[1], 2)[0]);

            return $filtered === null ? $base : $base . '?' . $filtered;
        } catch (\Throwable) {
            $split = preg_split('/[?#]/', $value, 2);

            return is_array($split) ? $split[0] : '';
        }
    }

    /**
     * "a=1&token=x" with the values of secret / personal names masked; null (leave the query
     * out) when it is over MAX_QUERY_BYTES, MAX_QUERY_PARAMS or MAX_PARAM_DEPTH. Names that a
     * framework could read in two ways ("a=1&a[b]=2", "list[][id]=1") mask every value.
     */
    public function queryString(string $query): ?string
    {
        try {
            if (strlen($query) > self::MAX_QUERY_BYTES) {
                return null;
            }
            if ($query === '') {
                return '';
            }
            $pairs = explode('&', $query);
            if (count($pairs) > self::MAX_QUERY_PARAMS) {
                return null;
            }
            $parsed = array_map(fn (string $pair): array => $this->parsePair($pair), $pairs);
            foreach ($parsed as $pair) {
                if ($pair['kind'] === 'too_deep') {
                    return null;
                }
            }
            $clear = $this->unambiguous($parsed);
            $out = [];
            foreach ($parsed as $pair) {
                $out[] = $this->pairText($pair, $clear);
            }

            return implode('&', $out);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function map(array $data, ?string $parentKey, ?string $parentFull, int $depth): array
    {
        $isList = array_is_list($data);
        $out = [];
        foreach ($data as $key => $value) {
            if ($isList && $parentKey !== null) {
                $out[$key] = $this->value($parentKey, $value, (string) $parentFull, $depth, true);
                continue;
            }
            if (is_string($key) && strlen($key) > Json::MAX_KEY_BYTES) {
                $out[Json::KEY_TOO_LONG] = '[TRUNCATED]';
                continue;
            }
            $name = (string) $key;
            $full = $parentFull === null ? $name : $parentFull . '.' . $name;
            $out[$key] = $this->value($name, $value, $full, $depth, false);
        }

        return $out;
    }

    private function value(string $key, mixed $value, string $full, int $depth, bool $checked): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::FILTERED;
        }
        if (!$checked && $this->matches($key, $full)) {
            return self::FILTERED;
        }
        if (is_array($value)) {
            return $this->map($value, $key, $full, $depth + 1);
        }

        return $value;
    }

    /**
     * @return array{raw: string, name: string, value: ?string, kind: string, path: list<string>, array: bool}
     */
    private function parsePair(string $pair): array
    {
        $parts = explode('=', $pair, 2);
        $out = ['raw' => $pair, 'name' => $parts[0], 'value' => null, 'kind' => 'ok', 'path' => [], 'array' => false];
        if (!array_key_exists(1, $parts)) {
            return $out;
        }
        $out['value'] = urldecode($parts[1]);
        if (preg_match(self::PARAM_NAME, urldecode($parts[0]), $match) !== 1) {
            $out['kind'] = 'ambiguous';

            return $out;
        }
        preg_match_all('/\[([^\[\]]*)\]/', $match[2], $found);
        $segments = $found[1];
        if (count($segments) > self::MAX_PARAM_DEPTH) {
            $out['kind'] = 'too_deep';

            return $out;
        }
        $isArray = $segments !== [] && $segments[count($segments) - 1] === '';
        if ($isArray) {
            array_pop($segments);
        }
        if (in_array('', $segments, true)) {
            $out['kind'] = 'ambiguous';

            return $out;
        }
        $out['path'] = array_merge([$match[1]], $segments);
        $out['array'] = $isArray;

        return $out;
    }

    /**
     * False when a name is ambiguous or names clash ("a=1&a[b]=2"): then every value is masked.
     *
     * @param list<array{raw: string, name: string, value: ?string, kind: string, path: list<string>, array: bool}> $pairs
     */
    private function unambiguous(array $pairs): bool
    {
        $types = [];
        foreach ($pairs as $pair) {
            if ($pair['value'] === null) {
                continue;
            }
            if ($pair['kind'] !== 'ok') {
                return false;
            }
            $path = $pair['path'];
            $last = count($path) - 1;
            for ($i = 0; $i < $last; $i++) {
                $key = implode("\0", array_slice($path, 0, $i + 1));
                if (isset($types[$key]) && $types[$key] !== 'hash') {
                    return false;
                }
                $types[$key] = 'hash';
            }
            $key = implode("\0", $path);
            $type = $pair['array'] ? 'list' : 'value';
            $existing = $types[$key] ?? null;
            if ($existing !== null && $existing !== $type) {
                return false;
            }
            $types[$key] = $type;
        }

        return true;
    }

    /**
     * @param array{raw: string, name: string, value: ?string, kind: string, path: list<string>, array: bool} $pair
     */
    private function pairText(array $pair, bool $clear): string
    {
        if ($pair['value'] === null) {
            return $pair['raw'];
        }
        $masked = $pair['name'] . '=' . self::FILTERED;
        if (!$clear) {
            return $masked;
        }
        if (preg_match(self::URL_SECRET, implode('.', $pair['path'])) === 1 || $this->pathMatches($pair['path'])) {
            return $masked;
        }

        return $pair['raw'];
    }

    /**
     * Whether the filter would mask the value at this nested path.
     *
     * @param list<string> $path
     */
    private function pathMatches(array $path): bool
    {
        $full = null;
        foreach ($path as $segment) {
            $full = $full === null ? $segment : $full . '.' . $segment;
            if ($this->matches($segment, $full)) {
                return true;
            }
        }

        return false;
    }
}
