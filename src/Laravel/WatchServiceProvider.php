<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use SyncRecord\Watch\Watch;

/**
 * Loaded by Laravel's package discovery (composer.json "extra.laravel"), so nothing needs to be
 * added to the app:
 * - starts Watch in every entry point, also `php artisan` (queue workers, the scheduler,
 *   commands), with the settings from config/watch.php (env(), so `config:cache` works too);
 * - reports every exception Laravel reports: requests, queued jobs, commands, and the ones the
 *   framework catches itself (a Redis or database connection that fails inside the queue worker).
 *   Laravel installs its own PHP exception handler, so without this they never reach Watch;
 * - long-running processes (queue workers, Octane): each job and request starts clean (no user,
 *   context or breadcrumbs of the one before), and errors in a job name the job.
 *
 * Exceptions in Laravel's "don't report" list (404s, validation errors...) are skipped, as in
 * Laravel's own log. Turn it off with WATCH_LARAVEL=false.
 */
final class WatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!self::wanted()) {
            return;
        }
        try {
            $this->mergeConfigFrom(__DIR__ . '/../../config/watch.php', 'watch');
            $settings = $this->app->make('config')->get('watch', []);
            Watch::start(is_array($settings) ? $settings : []);
        } catch (\Throwable) {
            // Never break the app's boot.
        }
    }

    public function boot(): void
    {
        if (!self::wanted()) {
            return;
        }
        try {
            if (method_exists($this, 'publishes')) {
                $this->publishes([__DIR__ . '/../../config/watch.php' => $this->app->configPath('watch.php')], 'watch-config');
            }
            if (Watch::client() === null) {
                return;
            }
            $handler = $this->app->make(ExceptionHandler::class);
            if (method_exists($handler, 'reportable')) {
                // Returns nothing, so Laravel goes on logging it as before.
                $handler->reportable(static function (\Throwable $error): void {
                    Watch::notify($error, [], false);
                });
            }
            $this->listenForJobsAndRequests();
        } catch (\Throwable) {
            // Never break the app's boot.
        }
    }

    private function listenForJobsAndRequests(): void
    {
        $events = $this->app->make('events');
        $events->listen('Illuminate\\Queue\\Events\\JobProcessing', static function (object $event): void {
            // A sync job (dispatchSync, QUEUE_CONNECTION=sync) runs inside the request or command: keep it.
            if (self::isSync($event)) {
                return;
            }
            $job = $event->job ?? null;
            $name = is_object($job) && method_exists($job, 'resolveName') ? (string) $job->resolveName() : null;
            Watch::client()?->releaseContext();
            if ($name !== null && $name !== '') {
                Watch::context(['job' => $name, 'queue' => is_object($job) && method_exists($job, 'getQueue') ? $job->getQueue() : null]);
            }
        });
        $events->listen('Illuminate\\Queue\\Events\\JobProcessed', static function (object $event): void {
            if (!self::isSync($event)) {
                Watch::client()?->releaseContext();
            }
        });
        $finished = static function (): void {
            Watch::client()?->releaseContext();
        };
        $events->listen('Laravel\\Octane\\Events\\RequestTerminated', $finished);
        $events->listen('Laravel\\Octane\\Events\\TaskTerminated', $finished);
    }

    private static function isSync(object $event): bool
    {
        return ($event->connectionName ?? null) === 'sync';
    }

    private static function wanted(): bool
    {
        $value = $_ENV['WATCH_LARAVEL'] ?? $_SERVER['WATCH_LARAVEL'] ?? getenv('WATCH_LARAVEL');

        return !is_string($value) || !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
    }
}
