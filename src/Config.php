<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * All settings. Each one comes from the array passed to Watch::start(), else from an
 * environment variable (getenv / $_ENV / $_SERVER), else a default. Most apps only set
 * WATCH_URL and WATCH_API_KEY.
 */
final class Config
{
    public const LEVELS = ['debug', 'info', 'warn', 'error', 'fatal', 'unknown'];

    /** Always masked (substring, case-insensitive), on top of WATCH_FILTER_KEYS. Same list as watch_client. */
    public const DEFAULT_FILTER_KEYS = [
        'passw', 'email', 'secret', 'token', '_key', 'crypt', 'salt', 'certificate', 'otp', 'ssn', 'cvv', 'cvc', 'iban',
    ];

    /** Exceptions never reported (subclasses too): mostly "not found" and validation noise. */
    public const DEFAULT_IGNORED_ERRORS = [
        'Symfony\\Component\\HttpKernel\\Exception\\NotFoundHttpException',
        'Symfony\\Component\\HttpKernel\\Exception\\MethodNotAllowedHttpException',
        'Illuminate\\Database\\Eloquent\\ModelNotFoundException',
        'Illuminate\\Auth\\AuthenticationException',
        'Illuminate\\Auth\\Access\\AuthorizationException',
        'Illuminate\\Validation\\ValidationException',
        'Illuminate\\Session\\TokenMismatchException',
        'Slim\\Exception\\HttpNotFoundException',
        'Slim\\Exception\\HttpMethodNotAllowedException',
        'CodeIgniter\\Exceptions\\PageNotFoundException',
        'Cake\\Http\\Exception\\NotFoundException',
        'yii\\web\\NotFoundHttpException',
    ];

    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1'];
    private const LOCAL_SUFFIXES = ['.localhost', '.test'];
    private const TRUE_VALUES = ['1', 'true', 'yes', 'on'];
    private const FALSE_VALUES = ['0', 'false', 'no', 'off'];

    public readonly ?string $url;
    public readonly ?string $apiKey;
    /** null = on when URL and key are set (and not in the test environment) */
    public readonly ?bool $enabled;
    public readonly string $environment;
    public readonly ?string $appVersion;
    public readonly ?string $appName;
    public readonly string $host;
    public readonly bool $allowHttp;
    public readonly bool $sendLogs;
    public readonly bool $sendErrors;
    public readonly bool $performance;
    public readonly string $minLevel;
    public readonly string $root;
    public readonly string $spoolDir;
    /** @var list<string> */
    public readonly array $filterKeys;
    public readonly bool $logRequests;
    public readonly bool $reportDependencies;
    public readonly bool $finishRequest;
    public readonly bool $warningsAsErrors;
    public readonly bool $captureErrors;
    /** null = automatic (CLI and FrankenPHP worker mode buffer in memory) */
    public readonly ?bool $workerMode;
    public readonly float $timeout;
    public readonly float $connectTimeout;
    public readonly int $batchSize;
    public readonly float $flushInterval;
    public readonly int $breadcrumbsMax;
    public readonly int $backtraceLines;
    public readonly int $sourceFrames;
    public readonly int $sourceLines;
    /** @var list<string> */
    public readonly array $ignoreErrors;
    public readonly ?\Closure $beforeNotify;
    public readonly ?\Closure $beforeSend;
    public readonly bool $diagnostics;

    /** @var array<string, mixed> */
    private array $options;

    /** @var array<string, mixed>|null */
    private ?array $env;

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed>|null $env replaces the process environment (tests)
     */
    public static function fromArray(array $options = [], ?array $env = null): self
    {
        return new self($options, $env);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed>|null $env
     */
    private function __construct(array $options, ?array $env)
    {
        $this->options = $options;
        $this->env = $env;

        $this->url = $this->string('url', 'WATCH_URL');
        $this->apiKey = $this->string('api_key', 'WATCH_API_KEY');
        $this->enabled = $this->nullableBool('enabled', 'WATCH_ENABLED');
        $this->environment = Text::cutChars($this->detectEnvironment(), 32);
        $this->appVersion = $this->detectAppVersion();
        $this->appName = $this->string('app_name', 'WATCH_APP_NAME');
        $this->host = $this->detectHost();
        $this->allowHttp = $this->bool('allow_http', 'WATCH_ALLOW_HTTP', false);
        $this->sendLogs = $this->bool('send_logs', 'WATCH_SEND_LOGS', true);
        $this->sendErrors = $this->bool('send_errors', 'WATCH_SEND_ERRORS', true);
        $this->performance = $this->bool('performance', 'WATCH_PERFORMANCE', true);
        $this->minLevel = self::normalizeLevel($this->string('min_level', 'WATCH_MIN_LEVEL') ?? 'info');
        $this->root = self::normalizePath($this->string('root', 'WATCH_ROOT') ?? self::detectRoot());
        $this->spoolDir = $this->string('spool_dir', 'WATCH_SPOOL_DIR') ?? $this->defaultSpoolDir();
        $this->filterKeys = $this->detectFilterKeys();
        $this->logRequests = $this->bool('log_requests', 'WATCH_LOG_REQUESTS', false);
        $this->reportDependencies = $this->bool('report_dependencies', 'WATCH_REPORT_DEPENDENCIES', true);
        $this->finishRequest = $this->bool('finish_request', 'WATCH_FINISH_REQUEST', true);
        $this->warningsAsErrors = $this->bool('warnings_as_errors', 'WATCH_WARNINGS_AS_ERRORS', false);
        $this->captureErrors = $this->bool('capture_errors', 'WATCH_CAPTURE_ERRORS', true);
        $this->workerMode = $this->nullableBool('worker_mode', 'WATCH_WORKER_MODE');
        $this->timeout = $this->float('timeout', 'WATCH_TIMEOUT', 5.0, 0.1, 60.0);
        $this->connectTimeout = $this->float('connect_timeout', 'WATCH_CONNECT_TIMEOUT', 2.0, 0.1, 60.0);
        $this->batchSize = $this->int('batch_size', 'WATCH_BATCH_SIZE', 500, 1, 1000);
        $this->flushInterval = $this->float('flush_interval', 'WATCH_FLUSH_INTERVAL', 10.0, 0.0, 3600.0);
        $this->breadcrumbsMax = $this->int('breadcrumbs_max', null, 40, 0, 100);
        $this->backtraceLines = $this->int('backtrace_lines', null, 100, 1, 200);
        $this->sourceFrames = $this->int('source_frames', null, 10, 0, 20);
        $this->sourceLines = $this->int('source_lines', null, 3, 0, 10);
        $this->ignoreErrors = $this->detectIgnoredErrors();
        $this->beforeNotify = $this->closure('before_notify');
        $this->beforeSend = $this->closure('before_send');
        $this->diagnostics = $this->bool('diagnostics', 'WATCH_DIAGNOSTICS', true);
    }

    /**
     * Whether anything is sent: URL and key set, the URL is safe, not switched off, and not
     * the test environment (unless WATCH_ENABLED=true).
     */
    public function isEnabled(): bool
    {
        if ($this->enabled === false || $this->url === null || $this->apiKey === null) {
            return false;
        }
        if (!$this->isSecureUrl()) {
            return false;
        }

        return $this->enabled === true || !$this->isTestEnvironment();
    }

    public function isTestEnvironment(): bool
    {
        return in_array(strtolower($this->environment), ['test', 'testing'], true);
    }

    /**
     * Whether data (and the API key) may be sent to this URL: https, http to a local
     * development host, or any http when allow_http is on.
     */
    public function isSecureUrl(?string $url = null): bool
    {
        return self::secureUrl($url ?? (string) $this->url, $this->allowHttp);
    }

    public static function secureUrl(string $url, bool $allowHttp): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && ($allowHttp || self::isLocalHost($parts['host']));
    }

    public static function isLocalHost(string $host): bool
    {
        $name = strtolower(trim($host, '[]'));
        if (in_array($name, self::LOCAL_HOSTS, true)) {
            return true;
        }
        if (preg_match('/\A127\.\d{1,3}\.\d{1,3}\.\d{1,3}\z/', $name) === 1) {
            return true;
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    public function minLevelIndex(): int
    {
        $index = array_search($this->minLevel, self::LEVELS, true);

        return is_int($index) ? $index : 1;
    }

    /**
     * Problems that keep Watch from sending (empty when it is ready).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if ($this->url === null) {
            $problems[] = 'url is missing (WATCH_URL)';
        } elseif (!$this->isSecureUrl()) {
            $problems[] = 'url must use https:// (http:// only for localhost / *.test, or set WATCH_ALLOW_HTTP=true)';
        }
        if ($this->apiKey === null) {
            $problems[] = 'api_key is missing (WATCH_API_KEY)';
        } elseif (!str_starts_with($this->apiKey, 'lh_')) {
            $problems[] = 'api_key should start with lh_ (the server key of the app, not the browser key)';
        }
        if ($this->enabled === false) {
            $problems[] = 'switched off (WATCH_ENABLED=false)';
        } elseif ($this->enabled === null && $this->isTestEnvironment()) {
            $problems[] = 'off in the "' . $this->environment . '" environment (set WATCH_ENABLED=true to send anyway)';
        }

        return $problems;
    }

    /**
     * The settings as text, API key masked to its last 4 characters.
     *
     * @return array<string, string>
     */
    public function describe(): array
    {
        $flag = static fn (bool $value): string => $value ? 'true' : 'false';

        return [
            'url' => $this->url ?? '(not set)',
            'api_key' => self::maskKey($this->apiKey),
            'enabled' => $flag($this->isEnabled()),
            'environment' => $this->environment,
            'app_version' => $this->appVersion ?? '(not set)',
            'app_name' => $this->appName ?? '(not set)',
            'host' => $this->host,
            'root' => $this->root,
            'spool_dir' => $this->spoolDir,
            'send_logs' => $flag($this->sendLogs),
            'send_errors' => $flag($this->sendErrors),
            'performance' => $flag($this->performance),
            'min_level' => $this->minLevel,
            'log_requests' => $flag($this->logRequests),
            'report_dependencies' => $flag($this->reportDependencies),
            'finish_request' => $flag($this->finishRequest),
            'filter_keys' => implode(', ', $this->filterKeys),
            'allow_http' => $flag($this->allowHttp),
            'transport' => function_exists('curl_init') ? 'curl' : 'php streams',
            'gzip' => $flag(function_exists('gzencode')),
        ];
    }

    public static function maskKey(?string $key): string
    {
        if ($key === null || $key === '') {
            return '(not set)';
        }
        if (strlen($key) <= 4) {
            return str_repeat('*', strlen($key));
        }

        return str_repeat('*', min(8, strlen($key) - 4)) . substr($key, -4);
    }

    /**
     * An environment variable (process environment, $_ENV, $_SERVER), or null when unset or empty.
     */
    public function env(string $name): ?string
    {
        if ($this->env !== null) {
            $value = $this->env[$name] ?? null;
        } else {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
            if ($value === null) {
                $fromProcess = getenv($name);
                $value = $fromProcess === false ? null : $fromProcess;
            }
        }
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * The project root: the directory that holds vendor/ (found through Composer's class loader),
     * else the current directory.
     */
    public static function detectRoot(): string
    {
        $loader = 'Composer\\Autoload\\ClassLoader';
        if (class_exists($loader, false)) {
            try {
                $file = (new \ReflectionClass($loader))->getFileName();
                if (is_string($file)) {
                    return self::normalizePath(dirname($file, 3));
                }
            } catch (\ReflectionException) {
                // Fall through.
            }
        }
        $here = self::normalizePath(__DIR__);
        $position = strpos($here, '/vendor/');
        if ($position !== false) {
            return substr($here, 0, $position);
        }
        $cwd = getcwd();

        return $cwd === false ? '' : self::normalizePath($cwd);
    }

    public static function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '/' || $path === '') {
            return $path;
        }

        return rtrim($path, '/');
    }

    public static function normalizeLevel(string $level): string
    {
        $name = strtolower(trim($level));
        $aliases = ['warning' => 'warn', 'notice' => 'info', 'err' => 'error', 'critical' => 'fatal',
            'crit' => 'fatal', 'alert' => 'fatal', 'emergency' => 'fatal', 'trace' => 'debug'];
        $name = $aliases[$name] ?? $name;

        return in_array($name, self::LEVELS, true) ? $name : 'info';
    }

    private function detectEnvironment(): string
    {
        return $this->string('environment', 'WATCH_ENV')
            ?? $this->env('WATCH_ENVIRONMENT')
            ?? $this->env('APP_ENV')
            ?? 'production';
    }

    private function detectAppVersion(): ?string
    {
        $value = $this->string('app_version', 'WATCH_APP_VERSION')
            ?? $this->env('HEROKU_SLUG_COMMIT')
            ?? $this->env('GIT_SHA')
            ?? $this->env('SOURCE_VERSION');

        return $value === null ? null : substr($value, 0, 12);
    }

    private function detectHost(): string
    {
        $host = $this->string('host', 'WATCH_HOST') ?? $this->env('DYNO') ?? $this->env('HOSTNAME');
        if ($host === null) {
            $name = gethostname();
            $host = is_string($name) && $name !== '' ? $name : 'unknown';
        }

        return Text::cutChars($host, 255);
    }

    private function defaultSpoolDir(): string
    {
        $hash = substr(hash('sha256', (string) $this->url . '|' . (string) $this->apiKey), 0, 16);

        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'watch-php-' . $hash;
    }

    /**
     * @return list<string>
     */
    private function detectFilterKeys(): array
    {
        $extra = $this->options['filter_keys'] ?? $this->env('WATCH_FILTER_KEYS') ?? [];
        if (is_string($extra)) {
            $extra = explode(',', $extra);
        }
        $keys = self::DEFAULT_FILTER_KEYS;
        foreach (is_array($extra) ? $extra : [] as $key) {
            if (is_string($key) && trim($key) !== '') {
                $keys[] = strtolower(trim($key));
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private function detectIgnoredErrors(): array
    {
        $list = self::DEFAULT_IGNORED_ERRORS;
        $extra = $this->options['ignore_errors'] ?? [];
        foreach (is_array($extra) ? $extra : [] as $class) {
            if (is_string($class) && $class !== '') {
                $list[] = ltrim($class, '\\');
            }
        }

        return array_values(array_unique($list));
    }

    private function raw(string $option, ?string $envName): mixed
    {
        if (array_key_exists($option, $this->options) && $this->options[$option] !== null) {
            return $this->options[$option];
        }

        return $envName === null ? null : $this->env($envName);
    }

    private function string(string $option, ?string $envName): ?string
    {
        return Text::presence($this->raw($option, $envName), 4096);
    }

    private function nullableBool(string $option, ?string $envName): ?bool
    {
        $value = $this->raw($option, $envName);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $text = strtolower(trim($value));
            if (in_array($text, self::TRUE_VALUES, true)) {
                return true;
            }
            if (in_array($text, self::FALSE_VALUES, true)) {
                return false;
            }
        }

        return null;
    }

    private function bool(string $option, ?string $envName, bool $default): bool
    {
        return $this->nullableBool($option, $envName) ?? $default;
    }

    private function int(string $option, ?string $envName, int $default, int $min, int $max): int
    {
        $value = $this->raw($option, $envName);
        if (!is_int($value) && !(is_string($value) && preg_match('/\A\s*-?\d+\s*\z/', $value) === 1)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    private function float(string $option, ?string $envName, float $default, float $min, float $max): float
    {
        $value = $this->raw($option, $envName);
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value)))) {
            return $default;
        }
        $number = (float) (is_string($value) ? trim($value) : $value);
        if (!is_finite($number)) {
            return $default;
        }

        return max($min, min($max, $number));
    }

    private function closure(string $option): ?\Closure
    {
        $value = $this->options[$option] ?? null;

        return is_callable($value) ? \Closure::fromCallable($value) : null;
    }
}
