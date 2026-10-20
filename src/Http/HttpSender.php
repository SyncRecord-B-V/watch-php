<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Http;

/**
 * Sends one POST request. Implementations never throw: failures come back in the response.
 */
interface HttpSender
{
    /**
     * @param array<string, string> $headers
     * @param float|null $timeout at most this many seconds (never more than the sender's own timeout)
     */
    public function send(string $url, array $headers, string $body, ?float $timeout = null): HttpResponse;
}
