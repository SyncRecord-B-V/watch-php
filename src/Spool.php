<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * A small local buffer for logs and metrics, shared by all PHP processes on the server: one
 * append-only JSON-lines file per kind and minute ("logs-202610201234.jsonl"), written under
 * flock(). Whichever request runs after a minute is over sends that minute's files, under a
 * non-blocking lock so only one process sends. Files older than an hour, or a spool over
 * 20 MB, are deleted.
 */
final class Spool
{
    public const MAX_AGE = 3600;
    public const MAX_BYTES = 20 * 1024 * 1024;
    /** One kind's file for one minute stops growing at this size (the rest is dropped). */
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;
    /** Seconds after the end of a minute before its file is sent (late writers). */
    public const SETTLE = 2;

    private const FILE_PATTERN = '/\A(logs|metrics)-(\d{12})\.jsonl\z/';

    private ?bool $usable = null;

    /** @var resource|null */
    private $lock = null;

    public function __construct(private readonly string $dir)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Whether the directory exists (or could be made, when $create) and is writable.
     */
    public function usable(bool $create = true): bool
    {
        if ($this->usable === true) {
            return true;
        }
        if (!is_dir($this->dir)) {
            if (!$create || $this->usable === false) {
                return false;
            }
            if (!@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                $this->usable = false;

                return false;
            }
        }
        $this->usable = is_writable($this->dir);

        return $this->usable;
    }

    /**
     * Appends records to this minute's file. False only when the file can't be written (the
     * caller then sends directly); records over MAX_FILE_BYTES are dropped and count as written.
     *
     * @param list<array<mixed>> $records
     */
    public function append(string $kind, array $records, ?int $now = null): bool
    {
        $data = '';
        foreach ($records as $record) {
            $json = Json::encode($record);
            if ($json !== null) {
                $data .= $json . "\n";
            }
        }
        if ($data === '') {
            return true;
        }
        $handle = @fopen($this->path($kind, $now ?? time()), 'ab');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $stat = fstat($handle);
            $size = is_array($stat) ? (int) $stat['size'] : 0;
            $written = true;
            if ($size + strlen($data) <= self::MAX_FILE_BYTES) {
                $written = fwrite($handle, $data) === strlen($data);
                fflush($handle);
            }
            flock($handle, LOCK_UN);

            return $written;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Files of minutes that are over (oldest first).
     *
     * @return list<string>
     */
    public function dueFiles(string $kind, ?int $now = null): array
    {
        $now ??= time();
        $files = [];
        foreach ($this->files() as $name => $info) {
            if ($info['kind'] === $kind && $info['minute'] + 60 + self::SETTLE <= $now) {
                $files[$info['minute'] . $name] = $this->dir . '/' . $name;
            }
        }
        ksort($files, SORT_STRING);

        return array_values($files);
    }

    public function hasDue(?int $now = null): bool
    {
        return $this->dueFiles('metrics', $now) !== [] || $this->dueFiles('logs', $now) !== [];
    }

    /**
     * The records in a file (lines that are not JSON objects are skipped).
     *
     * @return list<array<mixed>>
     */
    public function read(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        try {
            flock($handle, LOCK_SH);
            $content = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
        $records = [];
        foreach (explode("\n", is_string($content) ? $content : '') as $line) {
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Replaces a file's records (what is left after a partial send).
     *
     * @param list<array<mixed>> $records
     */
    public function rewrite(string $path, array $records): bool
    {
        if ($records === []) {
            $this->delete($path);

            return true;
        }
        $data = '';
        foreach ($records as $record) {
            $json = Json::encode($record);
            if ($json !== null) {
                $data .= $json . "\n";
            }
        }
        $temporary = $path . '.tmp' . getmypid();
        if (@file_put_contents($temporary, $data, LOCK_EX) === false) {
            return false;
        }

        return @rename($temporary, $path);
    }

    public function delete(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Non-blocking: false when another process is sending.
     */
    public function acquireSenderLock(): bool
    {
        if ($this->lock !== null) {
            return true;
        }
        $handle = @fopen($this->dir . '/.sender.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->lock = $handle;

        return true;
    }

    public function releaseSenderLock(): void
    {
        if ($this->lock === null) {
            return;
        }
        flock($this->lock, LOCK_UN);
        fclose($this->lock);
        $this->lock = null;
    }

    /**
     * Whether sending is allowed now (not within the wait after a failed send).
     */
    public function sendAllowed(?int $now = null): bool
    {
        $next = $this->readState('next-send');

        return $next === null || preg_match('/\A\d{1,12}\z/', $next) !== 1 || (int) $next <= ($now ?? time());
    }

    public function deferSend(int $seconds, ?int $now = null): void
    {
        $this->writeState('next-send', (string) (($now ?? time()) + $seconds));
    }

    /**
     * Deletes files older than MAX_AGE, then the oldest until the spool is under MAX_BYTES, and
     * left-over temporary files. Returns the number of files deleted.
     */
    public function cleanup(?int $now = null): int
    {
        $now ??= time();
        $deleted = 0;
        $kept = [];
        foreach ($this->files() as $name => $info) {
            $path = $this->dir . '/' . $name;
            if ($info['minute'] < $now - self::MAX_AGE) {
                $this->delete($path);
                $deleted++;
                continue;
            }
            $kept[$info['minute'] . $name] = ['path' => $path, 'size' => $info['size']];
        }
        ksort($kept, SORT_STRING);
        $total = array_sum(array_column($kept, 'size'));
        foreach ($kept as $file) {
            if ($total <= self::MAX_BYTES) {
                break;
            }
            $this->delete($file['path']);
            $total -= $file['size'];
            $deleted++;
        }

        return $deleted + $this->cleanupTemporary($now);
    }

    public function readState(string $name): ?string
    {
        $path = $this->statePath($name);
        if (!is_file($path)) {
            return null;
        }
        $text = @file_get_contents($path, false, null, 0, 65536);

        return is_string($text) ? trim($text) : null;
    }

    public function writeState(string $name, string $value): bool
    {
        $path = $this->statePath($name);
        $temporary = $path . '.tmp' . getmypid();
        if (@file_put_contents($temporary, $value, LOCK_EX) === false) {
            return false;
        }

        return @rename($temporary, $path);
    }

    /**
     * The minute (Unix time) a spool file belongs to.
     */
    public static function minuteOf(string $path): ?int
    {
        if (preg_match(self::FILE_PATTERN, basename($path), $match) !== 1) {
            return null;
        }
        $stamp = $match[2];
        $time = gmmktime(
            (int) substr($stamp, 8, 2),
            (int) substr($stamp, 10, 2),
            0,
            (int) substr($stamp, 4, 2),
            (int) substr($stamp, 6, 2),
            (int) substr($stamp, 0, 4),
        );

        return $time === false ? null : $time;
    }

    public function path(string $kind, int $time): string
    {
        return $this->dir . '/' . $kind . '-' . gmdate('YmdHi', $time) . '.jsonl';
    }

    /**
     * @return array<string, array{kind: string, minute: int, size: int}>
     */
    private function files(): array
    {
        $names = @scandir($this->dir);
        if (!is_array($names)) {
            return [];
        }
        $out = [];
        foreach ($names as $name) {
            if (preg_match(self::FILE_PATTERN, $name, $match) !== 1) {
                continue;
            }
            $minute = self::minuteOf($name);
            if ($minute === null) {
                continue;
            }
            $size = @filesize($this->dir . '/' . $name);
            $out[$name] = ['kind' => $match[1], 'minute' => $minute, 'size' => is_int($size) ? $size : 0];
        }

        return $out;
    }

    private function cleanupTemporary(int $now): int
    {
        $deleted = 0;
        $names = @scandir($this->dir);
        foreach (is_array($names) ? $names : [] as $name) {
            if (!str_contains($name, '.tmp')) {
                continue;
            }
            $path = $this->dir . '/' . $name;
            $modified = @filemtime($path);
            if (is_int($modified) && $modified < $now - 600) {
                $this->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function statePath(string $name): string
    {
        return $this->dir . '/.' . preg_replace('/[^a-z0-9_-]/', '', strtolower($name)) . '.state';
    }
}
