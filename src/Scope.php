<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * What Watch knows about the current request or job: its ID, route, user, context fields,
 * breadcrumbs, and the time spent in the database and in outgoing HTTP calls.
 *
 * @internal
 */
final class Scope
{
    public ?string $requestId = null;
    public ?string $route = null;
    public ?string $userId = null;
    public ?string $userEmail = null;
    /** @var array<array-key, mixed> */
    public array $context = [];
    /** @var list<array<string, mixed>> */
    public array $breadcrumbs = [];
    public ?string $jobClass = null;
    public ?string $jobId = null;
    public ?string $queue = null;
    public float $dbMs = 0.0;
    public int $queries = 0;
    public float $httpMs = 0.0;
    public bool $isRequest = false;

    /**
     * @param array<string, mixed> $crumb
     */
    public function addBreadcrumb(array $crumb, int $max): void
    {
        $this->breadcrumbs[] = $crumb;
        $excess = count($this->breadcrumbs) - max(1, $max);
        if ($excess > 0) {
            $this->breadcrumbs = array_slice($this->breadcrumbs, $excess);
        }
    }
}
