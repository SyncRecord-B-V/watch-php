<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Builds the error notice Watch expects (POST /api/v1/errors), in the same shape as
 * watch_client's Notice: exception_class, message, backtrace, causes, occurred_at, environment,
 * host, app_version, request_id, component, handled, user_id, job, request, source, breadcrumbs,
 * session, cookies, cgi_env, app_env, notifier and context.
 */
final class ErrorReport
{
    public const MAX_MESSAGE_BYTES = 8192;
    public const MAX_CAUSES = 3;
    public const CAUSE_LINES = 10;
    public const MAX_SECTION_BYTES = 16384;
    public const MAX_CONTEXT_BYTES = 32768;
    public const FILTERED = '[FILTERED]';

    /** Never sent: credentials, CSRF tokens and headers that only repeat the visitor's IP address. */
    public const SECRET_HEADERS = [
        'HTTP_COOKIE', 'HTTP_AUTHORIZATION', 'HTTP_PROXY_AUTHORIZATION', 'HTTP_X_API_KEY', 'HTTP_X_CSRF_TOKEN',
        'HTTP_X_XSRF_TOKEN', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP',
        'HTTP_TRUE_CLIENT_IP', 'HTTP_FORWARDED',
    ];

    /** Header values that are sent (the names of all other headers are sent with "[FILTERED]"). */
    public const ALLOWED_HEADERS = [
        'HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_CACHE_CONTROL', 'HTTP_CONNECTION',
        'HTTP_CONTENT_LENGTH', 'HTTP_CONTENT_TYPE', 'HTTP_DNT', 'HTTP_HOST', 'HTTP_IF_MODIFIED_SINCE',
        'HTTP_IF_NONE_MATCH', 'HTTP_ORIGIN', 'HTTP_PRAGMA', 'HTTP_REFERER', 'HTTP_SEC_CH_UA',
        'HTTP_SEC_CH_UA_MOBILE', 'HTTP_SEC_CH_UA_PLATFORM', 'HTTP_SEC_FETCH_DEST', 'HTTP_SEC_FETCH_MODE',
        'HTTP_SEC_FETCH_SITE', 'HTTP_SEC_FETCH_USER', 'HTTP_UPGRADE_INSECURE_REQUESTS', 'HTTP_USER_AGENT',
        'HTTP_VIA', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PORT', 'HTTP_X_FORWARDED_PROTO',
        'HTTP_X_REQUEST_ID', 'HTTP_X_REQUESTED_WITH',
    ];

    public const URL_HEADERS = ['HTTP_REFERER'];

    public const CGI_KEYS = [
        'REQUEST_METHOD', 'SERVER_NAME', 'SERVER_PORT', 'SERVER_PROTOCOL', 'SERVER_SOFTWARE', 'SCRIPT_NAME',
        'PATH_INFO', 'REMOTE_ADDR', 'CONTENT_TYPE', 'CONTENT_LENGTH', 'HTTPS',
    ];

    private const FRAME = '/\A(?<file>.+?):(?<line>\d+)(?::in [`\'](?<method>.*)\')?/';

    private const FATAL_NAMES = [
        E_ERROR => 'E_ERROR',
        E_PARSE => 'E_PARSE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_USER_ERROR => 'E_USER_ERROR',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_WARNING => 'E_WARNING',
        E_NOTICE => 'E_NOTICE',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        2048 => 'E_STRICT',
    ];

    /**
     * A notice for an exception (and its getPrevious() chain).
     *
     * @param array<array-key, mixed> $context
     * @return array<string, mixed>
     */
    public static function fromThrowable(
        Config $config,
        Filter $filter,
        \Throwable $error,
        Scope $scope,
        ?Request $request = null,
        bool $handled = true,
        array $context = [],
        ?string $component = null,
        ?string $fingerprint = null,
        ?float $time = null,
    ): array {
        $notice = [
            'exception_class' => self::className($error),
            'message' => Text::clean($error->getMessage(), self::MAX_MESSAGE_BYTES),
            'backtrace' => Backtrace::fromThrowable($error, $config->root, $config->backtraceLines),
            'causes' => self::causes($config, $error),
        ];

        return self::complete($config, $filter, $notice, $scope, $request, $handled, $context, $component, $fingerprint, $time);
    }

    /**
     * A notice for a fatal error found by error_get_last() at shutdown.
     *
     * @param array<string, mixed> $error ["type", "message", "file", "line"]
     * @return array<string, mixed>
     */
    public static function fromFatal(
        Config $config,
        Filter $filter,
        array $error,
        Scope $scope,
        ?Request $request = null,
        ?float $time = null,
    ): array {
        $type = is_int($error['type'] ?? null) ? $error['type'] : E_ERROR;
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        $file = is_string($error['file'] ?? null) ? $error['file'] : '';
        $line = is_int($error['line'] ?? null) ? $error['line'] : 0;
        $class = self::errorName($type);
        if (preg_match('/\AUncaught ([A-Za-z_\x80-\xff\\\\][\w\x80-\xff\\\\]*)/', $message, $match) === 1) {
            // An uncaught exception that another handler let through: keep its class and message.
            $class = $match[1];
            $message = self::uncaughtMessage($message, $class);
        }
        $notice = [
            'exception_class' => $class,
            'message' => Text::clean($message, self::MAX_MESSAGE_BYTES),
            'backtrace' => $file === '' ? [] : [Backtrace::relative($file, $config->root) . ':' . $line],
            'causes' => [],
        ];

        return self::complete($config, $filter, $notice, $scope, $request, false, [], null, null, $time);
    }

    public static function className(\Throwable $error): string
    {
        $name = get_class($error);
        $position = strpos($name, "\0");

        return $position === false ? $name : substr($name, 0, $position);
    }

    public static function errorName(int $type): string
    {
        return self::FATAL_NAMES[$type] ?? 'E_' . $type;
    }

    /**
     * @return list<array{exception_class: string, message: string, backtrace: list<string>}>
     */
    public static function causes(Config $config, \Throwable $error): array
    {
        $list = [];
        $seen = [spl_object_id($error) => true];
        $cause = $error->getPrevious();
        while ($cause !== null && count($list) < self::MAX_CAUSES && !isset($seen[spl_object_id($cause)])) {
            $seen[spl_object_id($cause)] = true;
            $list[] = [
                'exception_class' => self::className($cause),
                'message' => Text::clean($cause->getMessage(), self::MAX_MESSAGE_BYTES),
                'backtrace' => Backtrace::fromThrowable($cause, $config->root, self::CAUSE_LINES),
            ];
            $cause = $cause->getPrevious();
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $notice
     * @param array<array-key, mixed> $context
     * @return array<string, mixed>
     */
    private static function complete(
        Config $config,
        Filter $filter,
        array $notice,
        Scope $scope,
        ?Request $request,
        bool $handled,
        array $context,
        ?string $component,
        ?string $fingerprint,
        ?float $time,
    ): array {
        $isHttp = $request !== null && $request->isHttp();
        $notice += [
            'occurred_at' => Time::iso($time ?? microtime(true), 6),
            'environment' => $config->environment,
            'host' => $config->host,
            'app_version' => $config->appVersion,
            'request_id' => $scope->requestId,
            'component' => $component ?? self::component($scope, $request),
            'handled' => $handled,
            'fingerprint' => $fingerprint,
            'user_id' => $scope->userId,
            'job' => self::job($scope),
            'request' => $isHttp ? self::requestInfo($filter, $request) : null,
            'source' => self::source($config, $notice['backtrace']),
            'breadcrumbs' => $scope->breadcrumbs === [] ? null : $scope->breadcrumbs,
            'session' => self::session(),
            'cookies' => $isHttp ? self::cookies($filter, $request) : null,
            'cgi_env' => $isHttp ? self::cgiEnv($filter, $request) : null,
            'app_env' => self::appEnv($config),
            'notifier' => ['name' => 'watch-php', 'version' => Watch::VERSION, 'language' => 'php'],
            'context' => self::context($filter, $scope, $context),
        ];

        return self::compact($notice);
    }

    /**
     * The job class for jobs, the route ("InvoiceController#show") or "GET /invoices/{id}" for
     * web requests, "script#command" for console commands.
     */
    public static function component(Scope $scope, ?Request $request): ?string
    {
        if ($scope->jobClass !== null) {
            return $scope->jobClass;
        }
        if ($request !== null && $request->isHttp()) {
            $method = $request->method();

            return Request::routeName($scope->route, $method) ?? Request::endpoint($method, $request->path());
        }

        return Request::cliComponent($request !== null ? $request->server : $_SERVER);
    }

    /**
     * @return array<string, string>|null
     */
    private static function job(Scope $scope): ?array
    {
        if ($scope->jobClass === null) {
            return null;
        }

        return self::compact(['class' => $scope->jobClass, 'id' => $scope->jobId, 'queue' => $scope->queue]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestInfo(Filter $filter, Request $request): array
    {
        $params = Json::safe(array_replace($request->query, $request->body));
        $params = is_array($params) ? $filter->apply($params) : [];
        if ((Json::bytes($params) ?? PHP_INT_MAX) > self::MAX_SECTION_BYTES) {
            $params = ['_truncated' => true];
        }
        $server = $request->server;
        $referrer = $filter->url(self::serverString($server, 'HTTP_REFERER'));
        $userAgent = self::serverString($server, 'HTTP_USER_AGENT');
        $remoteIp = self::serverString($server, 'REMOTE_ADDR');

        return self::compact([
            'method' => $request->method(),
            'url' => $filter->url($request->url()),
            'params' => $params === [] ? null : $params,
            'remote_ip' => $remoteIp === null ? null : Text::cutChars($remoteIp, 64),
            'referrer' => $referrer === null ? null : Text::cutChars($referrer, 1000),
            'user_agent' => $userAgent === null ? null : Text::cutChars($userAgent, 500),
            'headers' => self::headers($filter, $server),
        ]);
    }

    /**
     * All HTTP_* headers as "User-Agent" style names. Values only for the allow-list; secrets never.
     *
     * @param array<array-key, mixed> $server
     * @return array<string, mixed>|null
     */
    public static function headers(Filter $filter, array $server): ?array
    {
        $out = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $parts = explode('_', substr($key, 5));
            $name = implode('-', array_map(static fn (string $part): string => ucfirst(strtolower($part)), $parts));
            $out[$name] = self::headerValue($filter, $key, $value);
        }

        return self::limit($filter->apply($out));
    }

    /**
     * Server variables (server, path, remote address...) plus all headers, filtered.
     *
     * @return array<string, mixed>|null
     */
    private static function cgiEnv(Filter $filter, Request $request): ?array
    {
        $server = $request->server;
        $out = [];
        foreach (self::CGI_KEYS as $key) {
            $value = self::serverString($server, $key);
            if ($value !== null) {
                $out[$key] = Text::cutChars($value, 500);
            }
        }
        $query = self::serverString($server, 'QUERY_STRING');
        if ($query !== null) {
            $out['QUERY_STRING'] = $filter->queryString($query) ?? self::FILTERED;
        }
        $uri = self::serverString($server, 'REQUEST_URI');
        if ($uri !== null) {
            $out['REQUEST_URI'] = Text::cutChars((string) $filter->url($uri), 500);
        }
        foreach ($server as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $out[$key] = self::headerValue($filter, $key, $value);
            }
        }

        return self::limit($filter->apply($out));
    }

    private static function headerValue(Filter $filter, string $key, mixed $value): string
    {
        if (in_array($key, self::SECRET_HEADERS, true) || !in_array($key, self::ALLOWED_HEADERS, true)) {
            return self::FILTERED;
        }
        $text = is_scalar($value) ? (string) $value : '';
        if (in_array($key, self::URL_HEADERS, true)) {
            $text = (string) $filter->url($text);
        }

        return Text::cutChars($text, 500);
    }

    /**
     * Cookie names; values are never sent.
     *
     * @return array<string, mixed>|null
     */
    private static function cookies(Filter $filter, Request $request): ?array
    {
        $out = [];
        foreach ($request->cookies as $name => $value) {
            $out[(string) $name] = self::FILTERED;
        }

        return self::limit($filter->apply($out));
    }

    /**
     * Only the size of the session (number of keys, bytes), never its contents.
     *
     * @return array<string, int>|null
     */
    private static function session(): ?array
    {
        if (!function_exists('session_status') || session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }
        $encoded = function_exists('session_encode') ? session_encode() : false;

        return self::compact(['keys' => count($_SESSION), 'bytes' => is_string($encoded) ? strlen($encoded) : null]);
    }

    /**
     * Code around the first app frames: [["index", "file", "line", "method", "start", "lines"]].
     *
     * @param list<string> $backtrace
     * @return list<array<string, mixed>>
     */
    public static function source(Config $config, array $backtrace): array
    {
        if ($config->root === '' || $config->sourceFrames <= 0) {
            return [];
        }
        $files = [];
        $out = [];
        foreach ($backtrace as $index => $frame) {
            if (count($out) >= $config->sourceFrames) {
                break;
            }
            if (!self::isRelativeFrame($frame) || preg_match(self::FRAME, $frame, $match) !== 1) {
                continue;
            }
            $path = $config->root . '/' . $match['file'];
            if (!array_key_exists($path, $files)) {
                $files[$path] = self::readSource($path);
            }
            $lines = $files[$path];
            $number = (int) $match['line'];
            if ($lines === null || $number < 1 || $number > count($lines)) {
                continue;
            }
            $first = max($number - $config->sourceLines, 1);
            $last = min($number + $config->sourceLines, count($lines));
            $slice = array_slice($lines, $first - 1, $last - $first + 1);
            $out[] = [
                'index' => $index,
                'file' => $match['file'],
                'line' => $number,
                'method' => isset($match['method']) && $match['method'] !== '' ? $match['method'] : null,
                'start' => $first,
                'lines' => array_map(static fn (string $text): string => Text::cutChars(rtrim($text, "\r\n"), 300), $slice),
            ];
        }

        return $out;
    }

    /**
     * Facts about the running app: host, process, PHP and framework versions, memory and load.
     *
     * @return array<string, mixed>
     */
    public static function appEnv(Config $config): array
    {
        $framework = Dependencies::runtimeFramework();
        $stats = [
            'memory_mb' => round(memory_get_usage(true) / 1048576, 1),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (is_array($load)) {
                $stats['load'] = array_map(static fn ($value): float => round((float) $value, 2), array_slice($load, 0, 3));
            }
        }
        $pid = getmypid();

        return self::compact([
            'environment_name' => $config->environment,
            'hostname' => $config->host,
            'app_name' => $config->appName,
            'project_root' => $config->root === '' ? null : $config->root,
            'revision' => $config->appVersion,
            'pid' => is_int($pid) ? $pid : null,
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'framework' => $framework['name'] ?? null,
            'framework_version' => $framework['version'] ?? null,
            'stats' => $stats,
        ]);
    }

    /**
     * Watch::context() fields plus the notify() context, filtered. The user's e-mail from
     * Watch::setUser() is added after filtering: the app passed it on purpose.
     *
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>|null
     */
    private static function context(Filter $filter, Scope $scope, array $context): ?array
    {
        $extra = Json::safe(array_replace($scope->context, $context));
        $out = is_array($extra) && $extra !== [] ? $filter->apply($extra) : [];
        if ((Json::bytes($out) ?? PHP_INT_MAX) > self::MAX_CONTEXT_BYTES) {
            $out = ['_truncated' => true];
        }
        if ($scope->userEmail !== null) {
            $out['user'] = self::compact(['id' => $scope->userId, 'email' => $scope->userEmail]);
        }

        return $out === [] ? null : $out;
    }

    private static function isRelativeFrame(string $frame): bool
    {
        if ($frame === '' || $frame[0] === '/' || $frame[0] === '[' || $frame[0] === '\\') {
            return false;
        }

        return preg_match('/\A[A-Za-z]:[\/\\\\]/', $frame) !== 1;
    }

    /**
     * @return list<string>|null
     */
    private static function readSource(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $size = @filesize($path);
        if (!is_int($size) || $size >= 1000000) {
            return null;
        }
        $lines = @file($path);

        return is_array($lines) ? $lines : null;
    }

    private static function uncaughtMessage(string $message, string $class): string
    {
        $text = substr($message, strlen('Uncaught ' . $class));
        $text = (string) preg_replace('/\A: /', '', $text);
        $position = strpos($text, "\nStack trace:");
        if ($position !== false) {
            $text = substr($text, 0, $position);
        }

        return (string) preg_replace('/ in \S+:\d+\s*\z/', '', trim($text));
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function serverString(array $server, string $key): ?string
    {
        $value = $server[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $text = (string) $value;

        return $text === '' ? null : $text;
    }

    /**
     * @param array<array-key, mixed>|null $section
     * @return array<array-key, mixed>|null
     */
    private static function limit(?array $section): ?array
    {
        if ($section === null || $section === []) {
            return null;
        }

        return (Json::bytes($section) ?? PHP_INT_MAX) > self::MAX_SECTION_BYTES ? ['_truncated' => true] : $section;
    }

    /**
     * @template T of array
     * @param T $data
     * @return T
     */
    private static function compact(array $data): array
    {
        return array_filter($data, static fn ($value): bool => $value !== null);
    }
}
