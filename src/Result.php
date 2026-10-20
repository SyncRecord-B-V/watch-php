<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * What happened to one delivery: ok, retry (408/425/429/5xx or no answer) or drop (any other
 * answer, e.g. 400/401/413/422: sending the same data again won't help).
 */
final class Result
{
    public const OK = 'ok';
    public const RETRY = 'retry';
    public const DROP = 'drop';

    private function __construct(
        public readonly string $status,
        public readonly int $code,
        public readonly ?string $error,
        public readonly bool $timedOut,
    ) {
    }

    public static function ok(int $code = 202): self
    {
        return new self(self::OK, $code, null, false);
    }

    /**
     * @param bool $timedOut the request may have reached Watch (don't resend numbers that add up)
     */
    public static function retry(string $error, int $code = 0, bool $timedOut = false): self
    {
        return new self(self::RETRY, $code, $error, $timedOut);
    }

    public static function drop(string $error, int $code = 0): self
    {
        return new self(self::DROP, $code, $error, false);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function shouldRetry(): bool
    {
        return $this->status === self::RETRY;
    }

    public function isDropped(): bool
    {
        return $this->status === self::DROP;
    }

    public function describe(): string
    {
        if ($this->isOk()) {
            return 'ok (HTTP ' . $this->code . ')';
        }

        return $this->status . ': ' . ($this->error ?? 'HTTP ' . $this->code);
    }
}
