<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

use SyncRecord\Watch\Http\CurlSender;
use SyncRecord\Watch\Http\HttpResponse;
use SyncRecord\Watch\Http\HttpSender;
use SyncRecord\Watch\Http\StreamSender;

/**
 * Sends gzip JSON to Watch over HTTPS (plain HTTP only to local hosts or with allow_http) and
 * tells the caller what to do with the result.
 */
final class Transport implements TransportInterface
{
    public const LOGS = '/api/v1/logs';
    public const ERRORS = '/api/v1/errors';
    public const METRICS = '/api/v1/metrics';
    public const DEPLOYS = '/api/v1/deploys';
    public const DEPENDENCIES = '/api/v1/dependencies';

    public const RETRYABLE_CODES = [408, 425, 429, 500, 502, 503, 504];

    private readonly HttpSender $sender;

    public function __construct(private readonly Config $config, ?HttpSender $sender = null)
    {
        $this->sender = $sender ?? self::defaultSender($config);
    }

    public static function defaultSender(Config $config): HttpSender
    {
        if (function_exists('curl_init')) {
            return new CurlSender($config->timeout, $config->connectTimeout);
        }

        return new StreamSender($config->timeout);
    }

    public static function userAgent(): string
    {
        return 'watch-php/' . Watch::VERSION . ' php/' . PHP_VERSION;
    }

    public function post(string $path, array $payload): Result
    {
        try {
            $url = rtrim((string) $this->config->url, '/') . $path;
            if ($this->config->url === null || !$this->config->isSecureUrl($url)) {
                return Result::drop('refusing to send: WATCH_URL must use https:// (http:// only for localhost / *.test, or set WATCH_ALLOW_HTTP=true)');
            }
            $json = Json::encode($payload);
            if ($json === null) {
                return Result::drop('could not encode the payload as JSON');
            }
            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . (string) $this->config->apiKey,
                'User-Agent' => self::userAgent(),
            ];
            $body = $json;
            if (function_exists('gzencode')) {
                $compressed = gzencode($json);
                if (is_string($compressed)) {
                    $body = $compressed;
                    $headers['Content-Encoding'] = 'gzip';
                }
            }

            return self::classify($this->sender->send($url, $headers, $body));
        } catch (\Throwable $error) {
            return Result::drop('send failed: ' . $error->getMessage());
        }
    }

    /**
     * 2xx: ok. 408/425/429/500/502/503/504 and network errors: retry. Anything else: drop.
     */
    public static function classify(HttpResponse $response): Result
    {
        if ($response->error !== null) {
            return Result::retry($response->error, 0, $response->timedOut);
        }
        $code = $response->status;
        if ($code >= 200 && $code < 300) {
            return Result::ok($code);
        }
        if ($code === 0) {
            return Result::retry('no HTTP answer');
        }
        if (in_array($code, self::RETRYABLE_CODES, true)) {
            return Result::retry('HTTP ' . $code, $code, $code === 408);
        }

        return Result::drop('HTTP ' . $code . ': ' . Text::cutBytes(trim($response->body), 200), $code);
    }
}
