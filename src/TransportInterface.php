<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

interface TransportInterface
{
    /**
     * Sends one JSON object to a Watch API path ("/api/v1/logs"). Never throws.
     *
     * @param array<string, mixed> $payload
     */
    public function post(string $path, array $payload): Result;
}
