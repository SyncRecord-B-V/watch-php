<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * PHP hooks: uncaught exceptions, PHP warnings / notices / deprecations, fatal errors and the
 * end of the request. Previous handlers are always called, PHP's own behaviour (output, status,
 * logging) is kept, and nothing here ever throws its own exception into the app.
 *
 * @internal
 */
final class Handlers
{
    public const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

    /** Memory kept free so the fatal-error report still works after "Allowed memory size exhausted". */
    private const RESERVE_BYTES = 32768;

    /** @var callable|null */
    private $previousExceptionHandler = null;

    /** @var callable|null */
    private $previousErrorHandler = null;

    private bool $inErrorHandler = false;

    private ?string $reserve = null;

    public function __construct(private readonly Client $client)
    {
    }

    public function install(bool $captureErrors): void
    {
        if ($captureErrors) {
            $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
            $this->previousErrorHandler = set_error_handler([$this, 'handleError']);
            $this->reserve = str_repeat(' ', self::RESERVE_BYTES);
        }
        register_shutdown_function([$this, 'onShutdown']);
    }

    /**
     * Reports the exception, then hands it to the previous handler; without one it is thrown
     * again so PHP prints and logs it exactly as it would without Watch.
     */
    public function handleException(\Throwable $error): void
    {
        try {
            $this->client->reportUncaught($error);
        } catch (\Throwable) {
            // Never let Watch hide the app's exception.
        }
        if ($this->previousExceptionHandler !== null) {
            ($this->previousExceptionHandler)($error);

            return;
        }

        throw $error;
    }

    /**
     * Records the warning (unless silenced with @ or excluded by error_reporting), then lets the
     * previous handler or PHP's standard handler deal with it as before.
     */
    public function handleError(int $type, string $message, string $file = '', int $line = 0): bool
    {
        if (!$this->inErrorHandler && !$this->client->isSending() && (error_reporting() & $type) !== 0) {
            $this->inErrorHandler = true;
            try {
                $this->client->recordPhpError($type, $message, $file, $line);
            } catch (\Throwable) {
                // Ignore.
            } finally {
                $this->inErrorHandler = false;
            }
        }
        if ($this->previousErrorHandler !== null) {
            return ($this->previousErrorHandler)($type, $message, $file, $line) !== false;
        }

        return false;
    }

    /**
     * Registered at start. Registers the real work as the last shutdown function, so shutdown
     * functions the app added later still run (and print) before the response is finished.
     */
    public function onShutdown(): void
    {
        register_shutdown_function([$this, 'finalShutdown']);
    }

    public function finalShutdown(): void
    {
        $this->reserve = null;
        try {
            $error = error_get_last();
            if (is_array($error) && in_array($error['type'] ?? null, self::FATAL_TYPES, true)) {
                self::raiseMemoryLimit((string) ($error['message'] ?? ''));
                $this->client->reportFatal($error);
            }
            $this->client->shutdown();
        } catch (\Throwable $failure) {
            Diagnostics::log('shutdown handler failed: ' . $failure->getMessage());
        }
    }

    /**
     * After "Allowed memory size ... exhausted" give the report a little room (the script is
     * over anyway).
     */
    private static function raiseMemoryLimit(string $message): void
    {
        if (!str_contains($message, 'Allowed memory size')) {
            return;
        }
        $limit = ini_get('memory_limit');
        if (!is_string($limit) || preg_match('/\A(\d+)([KMG]?)\z/i', trim($limit), $match) !== 1) {
            return;
        }
        $bytes = (int) $match[1] * match (strtoupper($match[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
        @ini_set('memory_limit', (string) ($bytes + 16 * 1048576));
    }
}
