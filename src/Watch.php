<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

use SyncRecord\Watch\Adapter\Psr3Logger;

/**
 * The one class an app talks to. Start it once at the top of the front controller:
 *
 *   SyncRecord\Watch\Watch::start();
 *
 * Every method is safe to call when Watch is not started or not configured (it then does
 * nothing), and none of them ever throws into the app.
 */
final class Watch
{
    public const VERSION = '0.1.0';

    private static ?Client $client = null;
    private static bool $started = false;

    /**
     * Reads the settings (array, else WATCH_* environment variables), installs the error
     * handlers and the shutdown hook. Returns false when Watch is off (no URL or key, an
     * insecure URL, WATCH_ENABLED=false or the test environment).
     *
     * @param array<string, mixed> $options
     */
    public static function start(array $options = []): bool
    {
        try {
            if (self::$started) {
                return self::$client !== null;
            }
            self::$started = true;
            $config = Config::fromArray($options);
            Diagnostics::enable($config->diagnostics);
            if (!$config->isEnabled()) {
                if ($config->url !== null && $config->apiKey !== null && !$config->isSecureUrl()) {
                    Diagnostics::log('not sending anything: WATCH_URL must use https:// (http:// only for localhost / *.test, or set WATCH_ALLOW_HTTP=true)');
                }

                return false;
            }
            $client = new Client($config);
            $client->install();
            self::$client = $client;

            return true;
        } catch (\Throwable $failure) {
            Diagnostics::log('start failed: ' . $failure->getMessage());

            return false;
        }
    }

    public static function isStarted(): bool
    {
        return self::$client !== null;
    }

    /**
     * Reports an exception (handled = true: the app caught it).
     *
     * @param array<array-key, mixed> $context extra fields shown with the error (filtered)
     */
    public static function notify(
        \Throwable $error,
        array $context = [],
        bool $handled = true,
        ?string $fingerprint = null,
    ): void {
        try {
            self::$client?->notify($error, $context, $handled, $fingerprint);
        } catch (\Throwable) {
            // Never throw into the app.
        }
    }

    /**
     * One log line. Levels: debug, info, notice, warning (warn), error, critical, alert,
     * emergency (fatal). PSR-3 "{placeholders}" are filled from $context; the other context fields
     * are sent as structured fields (filtered); $context['exception'] may hold a Throwable.
     *
     * @param array<array-key, mixed> $context
     */
    public static function log(string|int $level, string|\Stringable $message, array $context = []): void
    {
        try {
            self::$client?->log($level, $message, $context);
        } catch (\Throwable) {
            // Never throw into the app.
        }
    }

    /**
     * A logger with PSR-3 methods ($logger->error('...', [...])). A real Psr\Log\LoggerInterface
     * when psr/log is installed.
     *
     * @return Logger|Psr3Logger
     */
    public static function logger(): object
    {
        if (interface_exists('Psr\\Log\\LoggerInterface') && class_exists('Psr\\Log\\AbstractLogger')) {
            return new Psr3Logger();
        }

        return new Logger();
    }

    /**
     * Names the current request for errors and performance ("InvoiceController#show",
     * "App\Http\Controllers\InvoiceController@show", "GET /invoices/{id}").
     */
    public static function setRoute(string $route): void
    {
        try {
            self::$client?->setRoute($route);
        } catch (\Throwable) {
            // Never throw into the app.
        }
    }

    /**
     * The signed-in user for errors and log lines. The e-mail address is only sent with errors.
     */
    public static function setUser(string|int|null $id, ?string $email = null): void
    {
        try {
            self::$client?->setUser($id, $email);
        } catch (\Throwable) {
            // Never throw into the app.
        }
    }

    /**
     * Fields added to every error and log line of this request or job (filtered).
     *
     * @param array<array-key, mixed> $fields
     */
    public static function context(array $fields): void
    {
        try {
            self::$client?->addContext($fields);
        } catch (\Throwable) {
            // Never throw into the app.
        }
    }

    /**
     * Something that happened before an error, shown with it (last 40 per request or job).
     *
     * @param array<string, mixed> $metadata
     */
    public static function addBreadcrumb(string $category, string $message, array $metadata = []): void
    {
        try {
            self::$client?->breadcrumb($category, $message, $metadata);
        } catch (\Throwable) {
            // Never throw into the app.
        }
    }

    /**
     * Runs a background job and times it: runs, failures and time per job class, errors with
     * the job class as component. The job's own exception is reported and thrown again.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function job(string $name, callable $work, ?string $queue = null): mixed
    {
        $client = self::$client;
        if ($client === null) {
            return $work();
        }

        return $client->job($name, $work, $queue);
    }

    /**
     * An outgoing HTTP call made without the Guzzle / PSR-18 adapters.
     *
     * @param array<array-key, mixed> $headers response headers, for API quotas
     * @param string|null $failure "timeout" or "connection" when there was no answer
     */
    public static function recordHttp(
        string $host,
        float $ms,
        ?int $status = null,
        array $headers = [],
        ?string $failure = null,
    ): void {
        try {
            self::$client?->recordHttp($host, $ms, $status, $headers, $failure);
        } catch (\Throwable) {
            // Never throw into the app.
        }
    }

    /**
     * Time spent in one database query (adds to the request's or job's database time).
     */
    public static function recordQuery(float $ms): void
    {
        try {
            self::$client?->recordQuery($ms);
        } catch (\Throwable) {
            // Never throw into the app.
        }
    }

    /**
     * Heartbeat check-in. Without $work: pings now. With $work: runs it, pings when it
     * succeeds, pings /fail with the exception when it fails (and throws the exception again).
     *
     * @param string $tokenOrUrl the heartbeat's token, or its full ping URL
     */
    public static function checkIn(string $tokenOrUrl, ?callable $work = null, ?string $message = null): mixed
    {
        if ($work === null) {
            return self::ping($tokenOrUrl, false, $message);
        }
        try {
            $result = $work();
        } catch (\Throwable $error) {
            self::ping($tokenOrUrl, true, ErrorReport::className($error) . ': ' . $error->getMessage());

            throw $error;
        }
        self::ping($tokenOrUrl, false, $message);

        return $result;
    }

    /**
     * Records a deploy (version: WATCH_APP_VERSION, else the git commit).
     */
    public static function deploy(?string $version = null, ?string $deployedBy = null, ?string $revision = null): bool
    {
        try {
            $config = self::$client?->config() ?? Config::fromArray();
            if (!$config->isEnabled()) {
                return false;
            }
            $result = Deploy::send($config, new Transport($config), $version, $deployedBy, $revision);
            if (!$result->isOk()) {
                Diagnostics::log('deploy not recorded: ' . $result->describe());
            }

            return $result->isOk();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sends everything buffered now. Long-running workers call this between jobs.
     */
    public static function flush(): void
    {
        try {
            self::$client?->flush();
        } catch (\Throwable) {
            // Never throw into the app.
        }
    }

    /**
     * Sends what is left; runs by itself at the end of every request and script.
     */
    public static function shutdown(): void
    {
        try {
            self::$client?->shutdown();
        } catch (\Throwable) {
            // Never throw into the app.
        }
    }

    /**
     * @internal for adapters
     */
    public static function client(): ?Client
    {
        return self::$client;
    }

    /**
     * @internal for tests: forget the started client (its PHP handlers stay installed).
     */
    public static function reset(?Client $client = null): void
    {
        self::$client = $client;
        self::$started = $client !== null;
    }

    private static function ping(string $tokenOrUrl, bool $failed, ?string $message): bool
    {
        try {
            $config = self::$client?->config() ?? Config::fromArray();

            return CheckIn::ping($config, Transport::defaultSender($config), $tokenOrUrl, $failed, $message);
        } catch (\Throwable) {
            return false;
        }
    }
}
