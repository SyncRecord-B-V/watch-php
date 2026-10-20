<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Small string helpers that work without ext-mbstring.
 *
 * @internal
 */
final class Text
{
    private const ANSI_COLOR = '/\e\[[\d;]*m/';

    /**
     * The first $max bytes, without a multi-byte character cut in half.
     */
    public static function cutBytes(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (strlen($text) <= $max) {
            return $text;
        }
        $cut = substr($text, 0, $max);
        for ($i = 0; $i < 3 && $cut !== '' && preg_match('//u', $cut) !== 1; $i++) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    /**
     * The first $max characters (UTF-8).
     */
    public static function cutChars(string $text, int $max): string
    {
        if ($max <= 0) {
            return '';
        }
        if (strlen($text) <= $max) {
            return $text;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $max, 'UTF-8');
        }
        if ($max <= 65535 && preg_match('/\A.{0,' . $max . '}/su', $text, $match) === 1) {
            return $match[0];
        }

        return self::cutBytes($text, $max);
    }

    /**
     * Message text as Watch stores it: ANSI colours removed, trimmed, at most $maxBytes.
     */
    public static function clean(string $text, int $maxBytes): string
    {
        $plain = preg_replace(self::ANSI_COLOR, '', $text);

        return self::cutBytes(trim($plain ?? $text), $maxBytes);
    }

    /**
     * A trimmed string, or null when the value is empty or not a scalar.
     */
    public static function presence(mixed $value, int $maxChars = 255): ?string
    {
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : self::cutChars($text, $maxChars);
    }
}
