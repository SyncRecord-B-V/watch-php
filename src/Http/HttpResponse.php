<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Http;

final class HttpResponse
{
    /**
     * @param int $status HTTP status, 0 when no answer arrived
     * @param string|null $error set when the request failed without an answer
     * @param bool $timedOut the request timed out after it was (partly) sent, so the server may
     *                       have stored it
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly ?string $error = null,
        public readonly bool $timedOut = false,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }
}
