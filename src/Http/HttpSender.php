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
     */
    public function send(string $url, array $headers, string $body): HttpResponse;
}
