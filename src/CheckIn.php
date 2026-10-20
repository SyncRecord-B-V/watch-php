<?php

declare(strict_types=1);

namespace SyncRecord\Watch;

use SyncRecord\Watch\Http\HttpSender;

/**
 * Heartbeat check-ins for cron jobs and workers: calls Watch's ping URL (/ping/<token>, or
 * /ping/<token>/fail for a failed run, with ?msg=). No API key needed: the token is the secret.
 */
final class CheckIn
{
    public const MESSAGE_LIMIT = 250;

    /**
     * The ping URL for a token (joined to WATCH_URL) or a full ping URL, or null when there is none.
     */
    public static function url(Config $config, string $tokenOrUrl, bool $failed = false, ?string $message = null): ?string
    {
        $value = trim($tokenOrUrl);
        if ($value === '') {
            return null;
        }
        $query = '';
        if (preg_match('#\Ahttps?://#i', $value) === 1) {
            $parts = explode('?', $value, 2);
            $base = (string) preg_replace('#/(fail|success)/?\z#', '', rtrim($parts[0], '/'));
            $query = $parts[1] ?? '';
        } else {
            if ($config->url === null) {
                return null;
            }
            $base = rtrim($config->url, '/') . '/ping/' . rawurlencode($value);
        }
        if ($failed) {
            $base .= '/fail';
        }
        $text = $message === null ? '' : trim(Text::cutChars($message, self::MESSAGE_LIMIT));
        if ($text !== '') {
            $query = ($query === '' ? '' : $query . '&') . 'msg=' . rawurlencode($text);
        }

        return $query === '' ? $base : $base . '?' . $query;
    }

    /**
     * Pings Watch. Never throws; true when Watch accepted the ping.
     */
    public static function ping(
        Config $config,
        HttpSender $sender,
        string $tokenOrUrl,
        bool $failed = false,
        ?string $message = null,
    ): bool {
        try {
            if ($config->enabled === false || ($config->enabled === null && $config->isTestEnvironment())) {
                return false;
            }
            $url = self::url($config, $tokenOrUrl, $failed, $message);
            if ($url === null) {
                Diagnostics::log('check-in not sent: no WATCH_URL for the token');

                return false;
            }
            if (!Config::secureUrl($url, $config->allowHttp)) {
                Diagnostics::log('check-in not sent: the ping URL must use https:// (http:// only for localhost / *.test)');

                return false;
            }
            $headers = ['Content-Type' => 'text/plain; charset=utf-8', 'User-Agent' => Transport::userAgent()];
            $body = $message === null ? '' : Text::cutChars($message, self::MESSAGE_LIMIT);
            $response = $sender->send($url, $headers, $body);
            if ($response->isSuccess()) {
                return true;
            }
            Diagnostics::log('check-in not accepted: ' . ($response->error ?? 'HTTP ' . $response->status));

            return false;
        } catch (\Throwable $error) {
            Diagnostics::log('check-in failed: ' . $error->getMessage());

            return false;
        }
    }
}
