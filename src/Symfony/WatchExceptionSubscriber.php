<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Symfony;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use SyncRecord\Watch\Watch;

/**
 * Symfony's ErrorHandler replaces PHP's exception handler, so exceptions in controllers and
 * console commands only reach Watch through these events. Register it once in config/services.yaml:
 *
 *     SyncRecord\Watch\Symfony\WatchExceptionSubscriber: ~
 *
 * (with autoconfigure on, the default, Symfony picks up the events by itself). It starts Watch at
 * the beginning of each request and command when public/index.php or bin/console didn't.
 *
 * Not reported, as in Symfony's own error log: HTTP exceptions under 500 (404, 403, 400, 429...)
 * and security exceptions Symfony turns into a login redirect or a 403.
 */
final class WatchExceptionSubscriber implements EventSubscriberInterface
{
    private const EXPECTED = [
        'Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException',
        'Symfony\\Component\\Security\\Core\\Exception\\AuthenticationException',
    ];

    public function __construct()
    {
        Watch::start();
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Only so Watch starts early in every request and command (the subscriber is created then).
            'kernel.request' => ['onStart', 4096],
            'console.command' => ['onStart', 4096],
            // Before Symfony's own error listener (priority 0), which logs it.
            'kernel.exception' => ['onException', 128],
            'console.error' => ['onConsoleError', 128],
        ];
    }

    public function onStart(object $event): void
    {
        // Watch started in the constructor.
    }

    public function onException(object $event): void
    {
        if (!method_exists($event, 'getThrowable')) {
            return;
        }
        $error = $event->getThrowable();
        if ($error instanceof \Throwable && !self::expected($error)) {
            Watch::notify($error, [], false);
        }
    }

    public function onConsoleError(object $event): void
    {
        if (method_exists($event, 'getError') && $event->getError() instanceof \Throwable) {
            Watch::notify($event->getError(), [], false);
        }
    }

    private static function expected(\Throwable $error): bool
    {
        if (is_a($error, 'Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface')
            && method_exists($error, 'getStatusCode') && (int) $error->getStatusCode() < 500) {
            return true;
        }
        foreach (self::EXPECTED as $class) {
            if (is_a($error, $class)) {
                return true;
            }
        }

        return false;
    }
}
