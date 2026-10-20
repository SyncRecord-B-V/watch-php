<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Http;

/**
 * POST with PHP's own http:// stream wrapper, for servers without ext-curl.
 */
final class StreamSender implements HttpSender
{
    public function __construct(private readonly float $timeout = 5.0)
    {
    }

    public function send(string $url, array $headers, string $body, ?float $timeout = null): HttpResponse
    {
        try {
            return $this->perform($url, $headers, $body, $timeout);
        } catch (\Throwable $error) {
            return new HttpResponse(0, '', 'request failed: ' . $error->getMessage());
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function perform(string $url, array $headers, string $body, ?float $timeout): HttpResponse
    {
        $limit = $timeout === null ? $this->timeout : max(0.5, min($this->timeout, $timeout));
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'Content-Length: ' . strlen($body);
        $lines[] = 'Connection: close';
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $lines),
                'content' => $body,
                'timeout' => $limit,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        error_clear_last();
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            $last = error_get_last();
            $message = is_array($last) ? (string) $last['message'] : 'connection failed';

            return new HttpResponse(0, '', $message, stripos($message, 'timed out') !== false);
        }
        try {
            $responseBody = stream_get_contents($stream);
            $meta = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }
        if (!empty($meta['timed_out'])) {
            return new HttpResponse(0, '', 'read timed out', true);
        }

        return new HttpResponse(self::status($meta['wrapper_data'] ?? null), is_string($responseBody) ? $responseBody : '');
    }

    /**
     * The status of the last "HTTP/1.1 200 OK" line (after redirects there can be more).
     */
    private static function status(mixed $headers): int
    {
        $status = 0;
        if (!is_array($headers)) {
            return $status;
        }
        foreach ($headers as $line) {
            if (is_string($line) && preg_match('#\AHTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return $status;
    }
}
