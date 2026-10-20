<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Adapter;

use SyncRecord\Watch\Watch;

/**
 * Turns a PSR-7 request / response (or failure) into Watch::recordHttp(). Uses duck typing so it
 * loads without psr/http-message.
 *
 * @internal
 */
final class HttpRecorder
{
    /**
     * @param object $request a Psr\Http\Message\RequestInterface
     * @param object|null $response a Psr\Http\Message\ResponseInterface, null when the call failed
     * @param int|float $start hrtime(true) when the call started
     */
    public static function record(object $request, ?object $response, int|float $start, mixed $failure = null): void
    {
        try {
            $client = Watch::client();
            if ($client === null || !method_exists($request, 'getUri')) {
                return;
            }
            $ms = (hrtime(true) - $start) / 1e6;
            $uri = $request->getUri();
            $status = null;
            $headers = [];
            if ($response !== null && method_exists($response, 'getStatusCode')) {
                $status = (int) $response->getStatusCode();
                $headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
            }
            $client->recordHttp(
                self::host($uri),
                $ms,
                $status,
                is_array($headers) ? $headers : [],
                $status === null ? self::failureKind($failure) : null,
                method_exists($request, 'getMethod') ? (string) $request->getMethod() : null,
                self::url($uri),
            );
        } catch (\Throwable) {
            // Never break the app's HTTP call.
        }
    }

    /**
     * "api.example.com", plus ":port" when it is not the default for the scheme.
     */
    public static function host(object $uri): string
    {
        $host = method_exists($uri, 'getHost') ? strtolower((string) $uri->getHost()) : '';
        $port = method_exists($uri, 'getPort') ? $uri->getPort() : null;
        $scheme = method_exists($uri, 'getScheme') ? strtolower((string) $uri->getScheme()) : '';
        $default = $scheme === 'http' ? 80 : 443;
        if (is_int($port) && $port !== $default) {
            $host .= ':' . $port;
        }

        return $host;
    }

    /**
     * "timeout" or "connection".
     */
    public static function failureKind(mixed $failure): string
    {
        if ($failure instanceof \Throwable) {
            if (method_exists($failure, 'getHandlerContext')) {
                $context = $failure->getHandlerContext();
                if (is_array($context) && ($context['errno'] ?? null) === 28) {
                    return 'timeout';
                }
            }
            if (preg_match('/timed? ?out|timeout/i', $failure->getMessage()) === 1) {
                return 'timeout';
            }
        }

        return 'connection';
    }

    /**
     * The URL without query string (Watch filters it again).
     */
    private static function url(object $uri): ?string
    {
        if (!method_exists($uri, 'getScheme') || !method_exists($uri, 'getPath')) {
            return null;
        }

        return strtolower((string) $uri->getScheme()) . '://' . self::host($uri) . (string) $uri->getPath();
    }
}
