<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Everything watch-php knows about one request (or job) while it runs: the request, its scope (route,
 * user, context, breadcrumbs, timings) and what happened to it. One per request context, so requests
 * that run at the same time (Swoole / OpenSwoole coroutines) never see each other's data.
 *
 * @internal
 */
final class RequestState
{
    public bool $recorded = false;
    public bool $uncaught = false;
    public bool $uncaughtReported = false;
    /** @var array<string, int> PHP warnings seen in this request (type:file:line => count) */
    public array $warnings = [];

    public function __construct(
        public Request $request,
        public Scope $scope,
        public ?float $serverStart,
        public int|float $startHr,
    ) {
    }
}
