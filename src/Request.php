<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * The current HTTP request as Watch sees it ($_SERVER, query, form fields, cookies), plus
 * helpers for endpoint names, timing and the response status.
 */
final class Request
{
    private const METHOD_PREFIX = '/\A(?:GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS|TRACE|CONNECT) /';
    private const CONTROLLER_PREFIXES = ['App\\Http\\Controllers\\', 'App\\Controller\\', 'App\\Controllers\\'];

    private ?string $requestId = null;

    /**
     * @param array<array-key, mixed> $server
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $body
     * @param array<array-key, mixed> $cookies
     * @param bool|null $http whether this is an HTTP request (null: a web SAPI with REQUEST_METHOD)
     */
    public function __construct(
        public readonly array $server,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $cookies = [],
        private readonly ?bool $http = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_COOKIE);
    }

    public function isHttp(): bool
    {
        if ($this->http !== null) {
            return $this->http;
        }

        return PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg'
            && isset($this->server['REQUEST_METHOD']) && is_string($this->server['REQUEST_METHOD']);
    }

    public function method(): string
    {
        $method = $this->server['REQUEST_METHOD'] ?? 'GET';
        $method = is_string($method) ? strtoupper(trim($method)) : 'GET';

        return preg_match('/\A[A-Z]{1,12}\z/', $method) === 1 ? $method : 'GET';
    }

    /**
     * The path without query string, e.g. "/invoices/42".
     */
    public function path(): string
    {
        $uri = $this->string('REQUEST_URI') ?? $this->string('PATH_INFO') ?? $this->string('SCRIPT_NAME') ?? '/';
        if (preg_match('#\A[a-z][a-z0-9+.-]*://#i', $uri) === 1) {
            $path = parse_url($uri, PHP_URL_PATH);
            $uri = is_string($path) ? $path : '/';
        }
        $path = explode('?', $uri, 2)[0];
        $path = explode('#', $path, 2)[0];

        return $path === '' ? '/' : $path;
    }

    /**
     * The path with query string ("/invoices?page=2"), unfiltered.
     */
    public function pathWithQuery(): string
    {
        $uri = $this->string('REQUEST_URI');
        if ($uri === null) {
            return $this->path();
        }

        return explode('#', $uri, 2)[0];
    }

    /**
     * The full URL, unfiltered (run it through Filter::url before sending).
     */
    public function url(): string
    {
        $host = $this->string('HTTP_HOST');
        if ($host === null) {
            $host = $this->string('SERVER_NAME') ?? 'localhost';
            $port = $this->string('SERVER_PORT');
            if ($port !== null && $port !== '80' && $port !== '443') {
                $host .= ':' . $port;
            }
        }
        $uri = $this->pathWithQuery();
        if (preg_match('#\A[a-z][a-z0-9+.-]*://#i', $uri) === 1) {
            return $uri;
        }

        return $this->scheme() . '://' . $host . ($uri === '' || $uri[0] !== '/' ? '/' . $uri : $uri);
    }

    public function scheme(): string
    {
        $https = $this->string('HTTPS');
        if ($https !== null && strtolower($https) !== 'off') {
            return 'https';
        }
        $forwarded = $this->string('HTTP_X_FORWARDED_PROTO');
        if ($forwarded !== null && strtolower(trim(explode(',', $forwarded)[0])) === 'https') {
            return 'https';
        }
        $scheme = $this->string('REQUEST_SCHEME');

        return $scheme !== null && strtolower($scheme) === 'https' ? 'https' : 'http';
    }

    /**
     * A request header by its usual name ("X-Request-Id").
     */
    public function header(string $name): ?string
    {
        return $this->string('HTTP_' . strtoupper(str_replace('-', '_', $name)));
    }

    /**
     * The X-Request-Id header when it looks like an ID, else a new random one (kept per request).
     */
    public function requestId(): string
    {
        if ($this->requestId !== null) {
            return $this->requestId;
        }
        $given = $this->header('X-Request-Id');
        if ($given !== null && preg_match('/\A[A-Za-z0-9._:\/+=@-]{1,100}\z/', $given) === 1) {
            return $this->requestId = $given;
        }

        return $this->requestId = self::newId();
    }

    /**
     * When the web server took the request (REQUEST_TIME_FLOAT), as a Unix time.
     */
    public function startTime(): ?float
    {
        $value = $this->server['REQUEST_TIME_FLOAT'] ?? null;
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    public static function newId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return str_replace('.', '', uniqid('', true));
        }
    }

    /**
     * The response status the app set (200 when it set none).
     */
    public static function status(): int
    {
        $code = http_response_code();

        return is_int($code) && $code > 0 ? $code : 200;
    }

    /**
     * Whether this process serves many requests (FrankenPHP worker mode); CLI is checked apart.
     */
    public static function isWorkerProcess(): bool
    {
        return isset($_SERVER['FRANKENPHP_WORKER']) || getenv('FRANKENPHP_WORKER') !== false;
    }

    /**
     * "GET /invoices/{id}": method plus the path with IDs replaced.
     */
    public static function endpoint(string $method, string $path): string
    {
        return Text::cutChars($method . ' ' . self::normalizePath($path), 255);
    }

    /**
     * The path with numeric, UUID and hex segments replaced by {id}, so one route is one name.
     */
    public static function normalizePath(string $path): string
    {
        $path = explode('?', $path, 2)[0];
        $path = explode('#', $path, 2)[0];
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $segments = explode('/', $path);
        foreach ($segments as $index => $segment) {
            if ($segment !== '' && self::isId(rawurldecode($segment))) {
                $segments[$index] = '{id}';
            }
        }

        return Text::cutChars(implode('/', $segments), 200);
    }

    /**
     * A route name as Watch shows it. "App\Http\Controllers\InvoiceController@show" and
     * "App\Controller\InvoiceController::show" become "InvoiceController#show"; a plain route name
     * gets the HTTP method in front ("GET invoice_show") so Watch sees a web request, not a job.
     */
    public static function routeName(?string $route, ?string $method = null): ?string
    {
        $route = $route === null ? '' : trim($route);
        if ($route === '') {
            return null;
        }
        if (preg_match('/\A\\\\?([A-Za-z_][\w\\\\]*)(?:::|@)([A-Za-z_]\w*)\z/', $route, $match) === 1) {
            $route = self::shortController($match[1]) . '#' . $match[2];
        } elseif (preg_match('/\A\\\\?[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)+\z/', $route) === 1) {
            $route = self::shortController(ltrim($route, '\\')) . '#__invoke';
        } elseif (!str_contains($route, '#') && preg_match(self::METHOD_PREFIX, $route) !== 1 && $method !== null) {
            $route = $method . ' ' . $route;
        }

        return Text::cutChars($route, 255);
    }

    /**
     * A console command as an error component ("artisan#queue:work"), so Watch doesn't take it
     * for a job class.
     *
     * @param array<array-key, mixed> $server
     */
    public static function cliComponent(array $server): ?string
    {
        $argv = $server['argv'] ?? null;
        if (!is_array($argv) || !isset($argv[0]) || !is_string($argv[0]) || $argv[0] === '') {
            return null;
        }
        $command = 'run';
        if (isset($argv[1]) && is_string($argv[1]) && $argv[1] !== '' && $argv[1][0] !== '-') {
            $command = $argv[1];
        }

        return Text::cutChars(basename($argv[0]) . '#' . $command, 255);
    }

    private static function shortController(string $class): string
    {
        $class = ltrim($class, '\\');
        foreach (self::CONTROLLER_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix) && strlen($class) > strlen($prefix)) {
                return substr($class, strlen($prefix));
            }
        }

        return $class;
    }

    private static function isId(string $segment): bool
    {
        if (preg_match('/\A\d+\z/', $segment) === 1) {
            return true;
        }
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $segment) === 1) {
            return true;
        }

        return strlen($segment) >= 8
            && preg_match('/\A[0-9a-f]+\z/i', $segment) === 1
            && preg_match('/\d/', $segment) === 1;
    }

    private function string(string $key): ?string
    {
        $value = $this->server[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $text = (string) $value;

        return $text === '' ? null : $text;
    }
}
