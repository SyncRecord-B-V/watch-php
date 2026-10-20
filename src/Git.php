<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Reads the current commit from .git without running git (exec is often disabled).
 *
 * @internal
 */
final class Git
{
    /**
     * The full commit hash of HEAD, or null outside a git checkout.
     */
    public static function head(string $root): ?string
    {
        try {
            $gitDir = self::gitDir($root);
            if ($gitDir === null) {
                return null;
            }
            $head = self::read($gitDir . '/HEAD');
            if ($head === null) {
                return null;
            }
            $sha = preg_match('/\Aref:\s*(\S+)/', $head, $match) === 1 ? self::resolve($gitDir, $match[1]) : $head;
        } catch (\Throwable) {
            return null;
        }

        return $sha !== null && preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/', $sha) === 1 ? $sha : null;
    }

    private static function resolve(string $gitDir, string $ref): ?string
    {
        if (str_contains($ref, '..') || !str_starts_with($ref, 'refs/')) {
            return null;
        }
        $sha = self::read($gitDir . '/' . $ref) ?? self::packedRef($gitDir, $ref);
        if ($sha !== null) {
            return $sha;
        }
        // A worktree keeps its branches in the main repository ("commondir").
        $common = self::read($gitDir . '/commondir');
        if ($common === null) {
            return null;
        }
        $dir = str_starts_with($common, '/') ? $common : $gitDir . '/' . $common;

        return self::read($dir . '/' . $ref) ?? self::packedRef($dir, $ref);
    }

    private static function gitDir(string $root): ?string
    {
        $path = $root . '/.git';
        if (is_dir($path)) {
            return $path;
        }
        $text = self::read($path);
        if ($text === null || preg_match('/\Agitdir:\s*(.+)\z/', $text, $match) !== 1) {
            return null;
        }
        $dir = trim($match[1]);

        return str_starts_with($dir, '/') ? $dir : $root . '/' . $dir;
    }

    private static function packedRef(string $gitDir, string $ref): ?string
    {
        $text = self::read($gitDir . '/packed-refs', 4194304);
        if ($text === null) {
            return null;
        }
        foreach (explode("\n", $text) as $line) {
            $parts = explode(' ', trim($line), 2);
            if (count($parts) === 2 && $parts[1] === $ref) {
                return $parts[0];
            }
        }

        return null;
    }

    private static function read(string $path, int $max = 4096): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $text = @file_get_contents($path, false, null, 0, $max);

        return is_string($text) ? trim($text) : null;
    }
}
