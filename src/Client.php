<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Collects errors, logs and metrics for one PHP process and delivers them:
 *
 * - Web requests (PHP-FPM, mod_php): errors are sent at the end of the request (after the
 *   response when fastcgi_finish_request() exists); logs and metrics go to the spool and are
 *   sent about once a minute per server. Without a writable spool everything is sent at the end
 *   of the request.
 * - CLI and long-running workers: in-memory buffers, sent every flush_interval seconds or
 *   batch_size log lines, on Watch::flush() and at shutdown; errors at once.
 *
 * @internal Use the Watch facade; adapters use the few public methods.
 */
final class Client
{
    public const MAX_ERRORS = 20;
    public const MAX_LOGS = 10000;
    public const MAX_WARNINGS = 100;
    /** Most seconds one request spends sending spool files. */
    private const SEND_BUDGET = 15.0;
    /** Wait after a failed spool send before the next try. */
    private const RETRY_AFTER = 60;

    private readonly Filter $filter;
    private readonly TransportInterface $transport;
    private readonly Spool $spool;
    private readonly bool $web;
    private readonly bool $bufferMode;
    private Request $request;
    private Scope $scope;
    private Metrics $metrics;
    private ?Handlers $handlers = null;
    /** @var list<array<string, mixed>> */
    private array $errors = [];
    /** @var list<array<string, mixed>> */
    private array $logs = [];
    /** @var \WeakMap<\Throwable, bool> */
    private \WeakMap $reported;
    /** @var array<string, int> */
    private array $warnings = [];
    private float $lastFlush;
    private ?float $serverStart;
    private int|float $startHr;
    private bool $requestRecorded = false;
    private bool $uncaught = false;
    private bool $uncaughtReported = false;
    private bool $finished = false;
    private bool $shutDown = false;
    private bool $sending = false;

    public function __construct(
        private readonly Config $config,
        ?TransportInterface $transport = null,
        ?Spool $spool = null,
        ?Request $request = null,
        ?bool $bufferMode = null,
    ) {
        $this->filter = new Filter($config->filterKeys);
        $this->transport = $transport ?? new Transport($config);
        $this->spool = $spool ?? new Spool($config->spoolDir);
        $this->request = $request ?? Request::fromGlobals();
        $this->web = $this->request->isHttp();
        $this->bufferMode = $bufferMode ?? $config->workerMode ?? (!$this->web || Request::isWorkerProcess());
        $this->metrics = new Metrics();
        $this->reported = new \WeakMap();
        $this->lastFlush = microtime(true);
        $this->serverStart = $this->bufferMode ? null : $this->request->startTime();
        $this->startHr = hrtime(true);
        $this->scope = $this->newScope($this->web);
    }

    public function install(): void
    {
        $this->handlers = new Handlers($this);
        $this->handlers->install($this->config->captureErrors);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function filter(): Filter
    {
        return $this->filter;
    }

    public function spool(): Spool
    {
        return $this->spool;
    }

    public function isBuffered(): bool
    {
        return $this->bufferMode;
    }

    /**
     * True while watch-php itself is sending (its own PHP warnings are not recorded).
     */
    public function isSending(): bool
    {
        return $this->sending;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingLogs(): array
    {
        return $this->logs;
    }

    public function metrics(): Metrics
    {
        return $this->metrics;
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function notify(
        \Throwable $error,
        array $context = [],
        bool $handled = true,
        ?string $fingerprint = null,
        ?string $component = null,
    ): void {
        try {
            if (!$this->config->sendErrors || isset($this->reported[$error]) || $this->isIgnored($error)) {
                return;
            }
            $this->reported[$error] = true;
            $notice = ErrorReport::fromThrowable(
                $this->config,
                $this->filter,
                $error,
                $this->scope,
                $this->request,
                $handled,
                $context,
                $component,
                $fingerprint,
            );
            $this->queueError($notice);
        } catch (\Throwable $failure) {
            Diagnostics::log('could not report an error: ' . $failure->getMessage());
        }
    }

    /**
     * Called by the exception handler for an exception nobody caught.
     */
    public function reportUncaught(\Throwable $error): void
    {
        $this->uncaught = true;
        $this->uncaughtReported = true;
        $this->notify($error, [], false);
    }

    /**
     * Called at shutdown for a fatal error from error_get_last().
     *
     * @param array<string, mixed> $error
     */
    public function reportFatal(array $error): void
    {
        try {
            $this->uncaught = true;
            $message = is_string($error['message'] ?? null) ? $error['message'] : '';
            if ($this->uncaughtReported && str_starts_with($message, 'Uncaught ')) {
                return;
            }
            if (!$this->config->sendErrors) {
                return;
            }
            $this->queueError(ErrorReport::fromFatal($this->config, $this->filter, $error, $this->scope, $this->request));
        } catch (\Throwable $failure) {
            Diagnostics::log('could not report a fatal error: ' . $failure->getMessage());
        }
    }

    /**
     * A PHP warning, notice or deprecation: one log line (warn) and a breadcrumb per place and
     * request, and an error when warnings_as_errors is on.
     */
    public function recordPhpError(int $type, string $message, string $file, int $line): void
    {
        try {
            if ($type === E_USER_ERROR || $type === E_RECOVERABLE_ERROR) {
                return; // Fatal unless handled elsewhere: reported at shutdown.
            }
            $key = $type . ':' . $file . ':' . $line;
            if (isset($this->warnings[$key])) {
                $this->warnings[$key]++;

                return;
            }
            if (count($this->warnings) >= self::MAX_WARNINGS) {
                return;
            }
            $this->warnings[$key] = 1;
            $location = Backtrace::relative($file, $this->config->root) . ':' . $line;
            $this->log('warn', self::phpErrorLabel($type) . ': ' . $message, [
                'php_error' => ErrorReport::errorName($type),
                'location' => $location,
            ]);
            if ($this->config->warningsAsErrors) {
                $this->notify(new \ErrorException($message, 0, $type, $file, $line), [], true);
            }
        } catch (\Throwable $failure) {
            Diagnostics::log('could not record a PHP warning: ' . $failure->getMessage());
        }
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(string|int $level, string|\Stringable $message, array $context = []): void
    {
        try {
            $name = LogEntry::levelName($level);
            $index = LogEntry::levelIndex($name);
            if ($index >= 2) {
                $this->breadcrumb('log', (string) $message, ['level' => $name]);
            }
            if (!$this->config->sendLogs || $index < $this->config->minLevelIndex()) {
                return;
            }
            $entry = LogEntry::build($this->config, $this->filter, $name, $message, $context, $this->scope);
            if ($this->config->beforeSend !== null) {
                $entry = ($this->config->beforeSend)($entry);
                if (!is_array($entry)) {
                    return;
                }
            }
            $this->logs[] = $entry;
            if (count($this->logs) > self::MAX_LOGS) {
                array_shift($this->logs);
            }
            $this->afterRecord();
        } catch (\Throwable $failure) {
            Diagnostics::log('could not record a log line: ' . $failure->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function breadcrumb(string $category, string $message, array $metadata = []): void
    {
        try {
            if ($this->config->breadcrumbsMax <= 0) {
                return;
            }
            $crumb = [
                'at' => Time::iso(microtime(true), 3),
                'category' => Text::cutChars($category, 40),
                'message' => Text::cutChars(Text::clean($message, 4096), 300),
            ];
            $metadata = array_filter($metadata, static fn ($value): bool => $value !== null);
            $safe = $metadata === [] ? [] : Json::safe($metadata);
            if (is_array($safe) && $safe !== []) {
                $crumb['metadata'] = $this->filter->apply($safe);
            }
            $this->scope->addBreadcrumb($crumb, $this->config->breadcrumbsMax);
        } catch (\Throwable) {
            // Breadcrumbs are best effort.
        }
    }

    public function setRoute(string $route): void
    {
        $this->scope->route = Text::presence($route, 255);
    }

    public function setUser(string|int|null $id, ?string $email = null): void
    {
        $this->scope->userId = Text::presence($id, 100);
        $this->scope->userEmail = Text::presence($email, 200);
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    public function addContext(array $fields): void
    {
        $this->scope->context = array_replace($this->scope->context, $fields);
    }

    /**
     * An outgoing HTTP call: metrics per host (with API quota headers) and a breadcrumb.
     *
     * @param array<array-key, mixed> $headers response headers (name => value or list of values)
     * @param string|null $failure "timeout" or "connection" when there was no answer
     */
    public function recordHttp(
        string $host,
        float $ms,
        ?int $status = null,
        array $headers = [],
        ?string $failure = null,
        ?string $method = null,
        ?string $url = null,
    ): void {
        try {
            $ms = is_finite($ms) && $ms > 0 ? $ms : 0.0;
            $host = Text::cutChars(strtolower(trim($host)), 255);
            $this->scope->httpMs += $ms;
            if ($this->config->performance && $host !== '') {
                $quotas = $headers === [] ? [] : ApiQuota::parse($headers);
                $this->metrics->recordHttp($host, $ms, $status, $failure, $quotas, $this->scope->jobClass);
            }
            $target = $url !== null ? (string) $this->filter->url($url) : $host;
            $outcome = $status !== null ? (string) $status : (string) $failure;
            $this->breadcrumb('http', trim(($method ?? '') . ' ' . $target . ' ' . $outcome), ['duration_ms' => round($ms, 1)]);
            $this->afterRecord();
        } catch (\Throwable $failure) {
            Diagnostics::log('could not record an HTTP call: ' . $failure->getMessage());
        }
    }

    public function recordQuery(float $ms): void
    {
        if (is_finite($ms) && $ms > 0) {
            $this->scope->dbMs += $ms;
        }
        $this->scope->queries++;
    }

    /**
     * Runs a job: its own scope (component = job class), timing, failures in metrics, and the
     * exception reported and thrown again.
     */
    public function job(string $name, callable $work, ?string $queue = null): mixed
    {
        $previous = $this->scope;
        $jobClass = Text::cutChars(trim($name), 255);
        $start = hrtime(true);
        try {
            $scope = new Scope();
            $scope->jobClass = $jobClass === '' ? 'UnnamedJob' : $jobClass;
            $scope->jobId = Request::newId();
            $scope->queue = Text::presence($queue, 100);
            $this->scope = $scope;
        } catch (\Throwable) {
            // Run the job anyway.
        }
        $failed = false;
        try {
            return $work();
        } catch (\Throwable $error) {
            $failed = true;
            $this->notify($error, [], false);
            throw $error;
        } finally {
            $this->finishJob($previous, $start, $failed);
        }
    }

    /**
     * Starts a new request in a long-running worker (PSR-15 middleware). Under PHP-FPM the request
     * began with the process, so only the request data is replaced.
     */
    public function beginRequest(Request $request): void
    {
        try {
            $this->request = $request;
            if (!$this->bufferMode) {
                return;
            }
            $this->scope = $this->newScope(true);
            $this->requestRecorded = false;
            $this->uncaught = false;
            $this->uncaughtReported = false;
            $this->warnings = [];
            $this->serverStart = null;
            $this->startHr = hrtime(true);
        } catch (\Throwable $failure) {
            Diagnostics::log('could not start a request: ' . $failure->getMessage());
        }
    }

    /**
     * Records the request's metrics (once): endpoint, time, status, database and HTTP time.
     */
    public function endRequest(?int $status = null): void
    {
        try {
            if ($this->requestRecorded || !$this->scope->isRequest) {
                return;
            }
            $this->requestRecorded = true;
            $code = $status ?? Request::status();
            if ($this->uncaught && $code < 500) {
                $code = 500;
            }
            $ms = $this->durationMs();
            $endpoint = $this->endpoint();
            if ($this->config->performance) {
                $this->metrics->recordRequest(
                    $endpoint,
                    $ms,
                    $code,
                    $this->scope->dbMs,
                    0.0,
                    $this->scope->httpMs,
                    $this->scope->queries,
                );
            }
            if ($this->config->logRequests) {
                $this->logRequest($endpoint, $code, $ms);
            }
            $this->afterRecord();
        } catch (\Throwable $failure) {
            Diagnostics::log('could not record the request: ' . $failure->getMessage());
        }
    }

    /**
     * Sends everything buffered now (errors, logs, metrics).
     */
    public function flush(): void
    {
        try {
            $this->flushBuffers();
        } catch (\Throwable $failure) {
            Diagnostics::log('flush failed: ' . $failure->getMessage());
        }
    }

    /**
     * End of the process or request: record the request, then deliver.
     */
    public function shutdown(): void
    {
        if ($this->shutDown) {
            return;
        }
        $this->shutDown = true;
        try {
            $this->endRequest();
            if ($this->bufferMode || !$this->spool->usable()) {
                if ($this->hasPending()) {
                    $this->finishResponse();
                }
                $this->flushBuffers();
                $this->maybeReportDependencies();

                return;
            }
            $this->spoolBuffers();
            $now = time();
            $sendDue = $this->spool->sendAllowed($now) && $this->spool->hasDue($now);
            if ($this->errors !== [] || $sendDue) {
                $this->finishResponse();
            }
            $this->sendErrors();
            if ($sendDue) {
                $this->sendSpool();
            }
        } catch (\Throwable $failure) {
            Diagnostics::log('shutdown failed: ' . $failure->getMessage());
        }
    }

    /**
     * Sends the spool files of finished minutes (one process at a time). Null when another
     * process is sending, false when Watch could not be reached.
     */
    public function sendSpool(): ?bool
    {
        if (!$this->spool->usable(false) || !$this->spool->acquireSenderLock()) {
            return null;
        }
        $this->sending = true;
        try {
            $now = time();
            $deadline = microtime(true) + self::SEND_BUDGET;
            $this->spool->cleanup($now);
            $ok = $this->sendSpoolKind('metrics', $now, $deadline) && $this->sendSpoolKind('logs', $now, $deadline);
            if (!$ok) {
                $this->spool->deferSend(self::RETRY_AFTER, $now);
            }
            $this->sending = false;
            $this->maybeReportDependencies();

            return $ok;
        } catch (\Throwable $failure) {
            Diagnostics::log('sending the spool failed: ' . $failure->getMessage());

            return false;
        } finally {
            $this->sending = false;
            $this->spool->releaseSenderLock();
        }
    }

    private function finishJob(Scope $previous, int|float $start, bool $failed): void
    {
        try {
            $ms = (hrtime(true) - $start) / 1e6;
            $scope = $this->scope;
            $this->scope = $previous;
            if ($this->config->performance && $scope->jobClass !== null) {
                $this->metrics->recordJob(
                    $scope->jobClass,
                    $ms,
                    $failed,
                    $scope->queue,
                    null,
                    $scope->dbMs,
                    $scope->queries,
                    $scope->httpMs,
                );
            }
            $this->afterRecord();
        } catch (\Throwable $failure) {
            $this->scope = $previous;
            Diagnostics::log('could not record a job: ' . $failure->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $notice
     */
    private function queueError(array $notice): void
    {
        if ($this->config->beforeNotify !== null) {
            $notice = ($this->config->beforeNotify)($notice);
            if (!is_array($notice)) {
                return;
            }
        }
        if (count($this->errors) >= self::MAX_ERRORS) {
            return;
        }
        $this->errors[] = $notice;
        if ($this->bufferMode) {
            $this->sendErrors();
        }
    }

    private function isIgnored(\Throwable $error): bool
    {
        foreach ($this->config->ignoreErrors as $class) {
            if ($error instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function newScope(bool $isRequest): Scope
    {
        $scope = new Scope();
        if (!$isRequest) {
            return $scope;
        }
        $scope->isRequest = true;
        $scope->requestId = $this->request->requestId();
        $this->scope = $scope;
        $this->breadcrumb('request', Request::endpoint($this->request->method(), $this->request->path()), [
            'method' => $this->request->method(),
            'path' => $this->filter->url($this->request->pathWithQuery()),
        ]);

        return $scope;
    }

    private function afterRecord(): void
    {
        if (!$this->bufferMode || $this->sending) {
            return;
        }
        $due = count($this->logs) >= $this->config->batchSize
            || microtime(true) - $this->lastFlush >= $this->config->flushInterval;
        if ($due) {
            $this->flushBuffers();
        }
    }

    private function hasPending(): bool
    {
        return $this->errors !== [] || $this->logs !== [] || !$this->metrics->isEmpty();
    }

    /**
     * Sends errors, logs and metrics directly (no spool).
     */
    private function flushBuffers(): void
    {
        if ($this->sending) {
            return;
        }
        $this->lastFlush = microtime(true);
        $this->sendErrors();
        $logs = $this->logs;
        $this->logs = [];
        foreach (array_chunk($logs, $this->config->batchSize) as $chunk) {
            $this->deliver(Transport::LOGS, ['entries' => $chunk], 1);
        }
        if (!$this->metrics->isEmpty()) {
            $payload = $this->metrics->payload($this->config);
            $this->metrics = new Metrics();
            $this->deliverMetrics($payload);
        }
        if ($this->bufferMode) {
            $this->warnings = [];
        }
    }

    private function sendErrors(): void
    {
        if ($this->errors === []) {
            return;
        }
        $batch = $this->errors;
        $this->errors = [];
        $this->deliver(Transport::ERRORS, ['errors' => $batch], 1);
    }

    /**
     * Logs and the request's metrics into the spool; what can't be written is sent directly.
     */
    private function spoolBuffers(): void
    {
        if ($this->logs !== [] && $this->spool->append('logs', $this->logs)) {
            $this->logs = [];
        }
        if (!$this->metrics->isEmpty() && $this->spool->append('metrics', [$this->metrics->rows()])) {
            $this->metrics = new Metrics();
        }
        if ($this->logs !== [] || !$this->metrics->isEmpty()) {
            $this->finishResponse();
            $this->flushBuffers();
        }
    }

    private function sendSpoolKind(string $kind, int $now, float $deadline): bool
    {
        foreach ($this->spool->dueFiles($kind, $now) as $file) {
            if (microtime(true) > $deadline) {
                return true;
            }
            $ok = $kind === 'metrics' ? $this->sendMetricsFile($file) : $this->sendLogsFile($file);
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private function sendMetricsFile(string $file): bool
    {
        $metrics = new Metrics();
        foreach ($this->spool->read($file) as $record) {
            $metrics->absorb($record);
        }
        if ($metrics->isEmpty()) {
            $this->spool->delete($file);

            return true;
        }
        $result = $this->deliverMetrics($metrics->payload($this->config, Spool::minuteOf($file)));
        if ($result->shouldRetry() && !$result->timedOut) {
            return false;
        }
        // Sent, refused, or maybe stored after a timeout: never send numbers that add up twice.
        $this->spool->delete($file);

        return true;
    }

    private function sendLogsFile(string $file): bool
    {
        $chunks = array_chunk($this->spool->read($file), $this->config->batchSize);
        foreach ($chunks as $index => $chunk) {
            $result = $this->deliver(Transport::LOGS, ['entries' => $chunk], 0);
            if ($result->shouldRetry()) {
                $this->spool->rewrite($file, array_merge(...array_slice($chunks, $index)));

                return false;
            }
        }
        $this->spool->delete($file);

        return true;
    }

    /**
     * Metrics are retried at most once, and not after a timeout (they may have been stored).
     *
     * @param array<string, mixed> $payload
     */
    private function deliverMetrics(array $payload): Result
    {
        $result = $this->deliver(Transport::METRICS, $payload, 0);
        if ($result->shouldRetry() && !$result->timedOut) {
            $result = $this->deliver(Transport::METRICS, $payload, 0);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function deliver(string $path, array $payload, int $retries): Result
    {
        $wasSending = $this->sending;
        $this->sending = true;
        try {
            $attempt = 0;
            while (true) {
                $result = $this->transport->post($path, $payload);
                if ($result->isOk()) {
                    return $result;
                }
                if ($result->shouldRetry() && $attempt < $retries) {
                    $attempt++;
                    usleep(250000 * $attempt);
                    continue;
                }
                Diagnostics::log('not sent to ' . $path . ': ' . $result->describe());

                return $result;
            }
        } catch (\Throwable $failure) {
            return Result::drop($failure->getMessage());
        } finally {
            $this->sending = $wasSending;
        }
    }

    private function maybeReportDependencies(): void
    {
        try {
            if (!$this->config->reportDependencies || !$this->spool->usable($this->web)) {
                return;
            }
            $now = time();
            $payload = Dependencies::due($this->config, $this->spool, $now);
            if ($payload === null) {
                return;
            }
            $result = $this->deliver(Transport::DEPENDENCIES, $payload, 0);
            if (!$result->shouldRetry()) {
                Dependencies::markSent($this->config, $this->spool, $payload, $now);
            }
        } catch (\Throwable $failure) {
            Diagnostics::log('could not report dependencies: ' . $failure->getMessage());
        }
    }

    /**
     * Lets the visitor go before Watch sends anything (PHP-FPM / LiteSpeed).
     */
    private function finishResponse(): void
    {
        if ($this->finished || !$this->web || $this->bufferMode || !$this->config->finishRequest) {
            return;
        }
        $this->finished = true;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    private function durationMs(): float
    {
        if ($this->serverStart !== null) {
            return max(0.0, (microtime(true) - $this->serverStart) * 1000);
        }

        return max(0.0, (hrtime(true) - $this->startHr) / 1e6);
    }

    private function endpoint(): string
    {
        $method = $this->request->method();

        return Request::routeName($this->scope->route, $method) ?? Request::endpoint($method, $this->request->path());
    }

    private function logRequest(string $endpoint, int $code, float $ms): void
    {
        $method = $this->request->method();
        $message = $method . ' ' . $this->request->path() . ' ' . $code . ' in ' . (int) round($ms) . 'ms';
        $route = Request::routeName($this->scope->route, $method);
        if ($route !== null) {
            $message .= ' (' . $route . ')';
        }
        $this->log($code >= 500 ? 'error' : 'info', $message, [
            'event' => 'request',
            'method' => $method,
            'path' => $this->filter->url($this->request->pathWithQuery()),
            'status' => $code,
            'duration_ms' => round($ms, 1),
            'endpoint' => $endpoint,
            'db_ms' => $this->scope->dbMs > 0 ? round($this->scope->dbMs, 1) : null,
            'http_ms' => $this->scope->httpMs > 0 ? round($this->scope->httpMs, 1) : null,
        ]);
    }

    private static function phpErrorLabel(int $type): string
    {
        return match ($type) {
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'Deprecated',
            default => 'PHP error',
        };
    }
}
