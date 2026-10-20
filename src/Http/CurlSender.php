<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Http;

/**
 * POST with ext-curl. One handle per sender, reused so a CLI process keeps its connection.
 */
final class CurlSender implements HttpSender
{
    private const CURLE_OPERATION_TIMEDOUT = 28;

    private ?\CurlHandle $handle = null;

    public function __construct(
        private readonly float $timeout = 5.0,
        private readonly float $connectTimeout = 2.0,
    ) {
    }

    public function send(string $url, array $headers, string $body, ?float $timeout = null): HttpResponse
    {
        try {
            return $this->perform($url, $headers, $body, $timeout);
        } catch (\Throwable $error) {
            $this->handle = null;

            return new HttpResponse(0, '', 'curl failed: ' . $error->getMessage());
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function perform(string $url, array $headers, string $body, ?float $timeout): HttpResponse
    {
        $limit = $timeout === null ? $this->timeout : max(0.5, min($this->timeout, $timeout));
        $handle = $this->handle();
        if ($handle === null) {
            return new HttpResponse(0, '', 'curl_init failed');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        // No "Expect: 100-continue" round trip for larger bodies.
        $lines[] = 'Expect:';

        curl_reset($handle);
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min($this->connectTimeout, $limit) * 1000),
            CURLOPT_TIMEOUT_MS => (int) round($limit * 1000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (!curl_setopt_array($handle, $options)) {
            return new HttpResponse(0, '', 'curl rejected the request options');
        }
        $result = curl_exec($handle);
        if ($result === false) {
            $errno = curl_errno($handle);
            $sent = (float) curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME) > 0.0;

            return new HttpResponse(
                0,
                '',
                'curl error ' . $errno . ': ' . curl_error($handle),
                $errno === self::CURLE_OPERATION_TIMEDOUT && $sent,
            );
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, is_string($result) ? $result : '');
    }

    private function handle(): ?\CurlHandle
    {
        if ($this->handle === null) {
            $handle = curl_init();
            $this->handle = $handle instanceof \CurlHandle ? $handle : null;
        }

        return $this->handle;
    }
}
