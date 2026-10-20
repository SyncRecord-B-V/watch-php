<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

/**
 * Backtrace lines in watch_client's format, so Watch groups PHP errors the same way:
 *
 *   src/Controller/InvoiceController.php:42:in 'App\Controller\InvoiceController->show'
 *
 * Paths inside the project root (and not under vendor/) are relative; "->" is an instance
 * method, "::" a static one, "{main}" top-level code. Each line names the function the line is
 * in (PHP's trace frame N says which function was called at file:line, so the label comes from
 * frame N+1).
 */
final class Backtrace
{
    /**
     * @return list<string>
     */
    public static function fromThrowable(\Throwable $error, string $root, int $max = 100): array
    {
        return self::fromFrames($error->getFile(), $error->getLine(), $error->getTrace(), $root, $max);
    }

    /**
     * @param array<int, array<string, mixed>> $trace frames as returned by getTrace() / debug_backtrace()
     * @return list<string>
     */
    public static function fromFrames(string $file, int $line, array $trace, string $root, int $max = 100): array
    {
        $trace = array_values($trace);
        $count = count($trace);
        $lines = [];
        for ($i = 0; $i <= $count && count($lines) < $max; $i++) {
            if ($i === 0) {
                $whereFile = $file;
                $whereLine = $line;
            } else {
                $caller = $trace[$i - 1];
                $whereFile = isset($caller['file']) && is_string($caller['file']) ? $caller['file'] : '';
                $whereLine = isset($caller['line']) && is_int($caller['line']) ? $caller['line'] : 0;
            }
            $label = $i < $count ? self::label($trace[$i]) : '{main}';
            $where = $whereFile === '' ? '[internal]:0' : self::relative($whereFile, $root) . ':' . $whereLine;
            $lines[] = $where . ":in '" . $label . "'";
        }

        return $lines;
    }

    /**
     * "Class->method", "Class::method", "function" or "{closure}".
     *
     * @param array<string, mixed> $frame
     */
    public static function label(array $frame): string
    {
        $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : '';
        if ($function === '') {
            return '{main}';
        }
        // PHP 8.4 names closures "{closure:App\Foo::bar():12}"; the line number would change the
        // group on every edit, so it is left out.
        $function = (string) preg_replace('/\{closure:.*\}/s', '{closure}', $function);
        $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
        if ($class === '') {
            return $function;
        }
        $type = isset($frame['type']) && $frame['type'] === '::' ? '::' : '->';

        return self::cleanClass($class) . $type . $function;
    }

    /**
     * The path relative to the project root when it is inside it and not under vendor/.
     */
    public static function relative(string $file, string $root): string
    {
        $path = str_replace('\\', '/', $file);
        if ($root === '' || $root === '/') {
            return $path;
        }
        $prefix = rtrim($root, '/') . '/';
        if (!str_starts_with($path, $prefix)) {
            return $path;
        }
        $rest = substr($path, strlen($prefix));
        if (str_starts_with($rest, 'vendor/') || str_contains($rest, '/vendor/')) {
            return $path;
        }

        return $rest;
    }

    /**
     * Anonymous classes are named "class@anonymous\0/path/file.php:12$0"; keep the readable part.
     */
    private static function cleanClass(string $class): string
    {
        $position = strpos($class, "\0");

        return $position === false ? $class : substr($class, 0, $position);
    }
}
