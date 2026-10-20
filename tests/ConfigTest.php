<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Config;

final class ConfigTest extends TestCase
{
    private const KEY = 'lh_exampleexampleexampleexampleexample1234';

    public function testDefaultsWithoutAnything(): void
    {
        $config = Config::fromArray([], []);

        $this->assertNull($config->url);
        $this->assertNull($config->apiKey);
        $this->assertFalse($config->isEnabled());
        $this->assertSame('production', $config->environment);
        $this->assertSame('info', $config->minLevel);
        $this->assertTrue($config->sendLogs);
        $this->assertTrue($config->sendErrors);
        $this->assertTrue($config->performance);
        $this->assertFalse($config->logRequests);
        $this->assertTrue($config->finishRequest);
        $this->assertSame(5.0, $config->timeout);
        $this->assertSame(2.0, $config->connectTimeout);
        $this->assertSame(500, $config->batchSize);
        $this->assertSame(10.0, $config->flushInterval);
        $this->assertSame(Config::DEFAULT_FILTER_KEYS, $config->filterKeys);
        $this->assertStringContainsString('watch-php-', $config->spoolDir);
    }

    public function testReadsTheEnvironment(): void
    {
        $config = Config::fromArray([], [
            'WATCH_URL' => 'https://watch.example.com',
            'WATCH_API_KEY' => self::KEY,
            'WATCH_ENV' => 'staging',
            'WATCH_APP_VERSION' => 'a1b2c3d4e5f6a7b8',
            'WATCH_MIN_LEVEL' => 'warning',
            'WATCH_SEND_LOGS' => 'false',
            'WATCH_LOG_REQUESTS' => 'true',
            'WATCH_FILTER_KEYS' => 'coupon, Pin ',
            'WATCH_ROOT' => '/var/www/app/',
            'WATCH_SPOOL_DIR' => '/tmp/spool',
        ]);

        $this->assertTrue($config->isEnabled());
        $this->assertSame('staging', $config->environment);
        $this->assertSame('a1b2c3d4e5f6', $config->appVersion);
        $this->assertSame('warn', $config->minLevel);
        $this->assertSame(2, $config->minLevelIndex());
        $this->assertFalse($config->sendLogs);
        $this->assertTrue($config->logRequests);
        $this->assertContains('coupon', $config->filterKeys);
        $this->assertContains('pin', $config->filterKeys);
        $this->assertContains('passw', $config->filterKeys);
        $this->assertSame('/var/www/app', $config->root);
        $this->assertSame('/tmp/spool', $config->spoolDir);
    }

    public function testTheArrayWinsOverTheEnvironment(): void
    {
        $config = Config::fromArray(
            ['url' => 'https://other.example.com', 'environment' => 'qa', 'performance' => false],
            ['WATCH_URL' => 'https://watch.example.com', 'WATCH_API_KEY' => self::KEY, 'WATCH_ENV' => 'staging'],
        );

        $this->assertSame('https://other.example.com', $config->url);
        $this->assertSame(self::KEY, $config->apiKey);
        $this->assertSame('qa', $config->environment);
        $this->assertFalse($config->performance);
    }

    public function testEnvironmentFallbacks(): void
    {
        $this->assertSame('local', Config::fromArray([], ['APP_ENV' => 'local'])->environment);
        $this->assertSame('demo', Config::fromArray([], ['WATCH_ENVIRONMENT' => 'demo', 'APP_ENV' => 'local'])->environment);
        $this->assertSame('x', Config::fromArray([], ['WATCH_ENV' => 'x', 'WATCH_ENVIRONMENT' => 'demo'])->environment);
    }

    public function testAppVersionFallbacks(): void
    {
        $this->assertSame('abcdef123456', Config::fromArray([], ['GIT_SHA' => 'abcdef1234567890'])->appVersion);
        $this->assertSame('heroku', Config::fromArray([], ['HEROKU_SLUG_COMMIT' => 'heroku', 'GIT_SHA' => 'x'])->appVersion);
        $this->assertNull(Config::fromArray([], [])->appVersion);
    }

    public function testOffWithoutUrlOrKey(): void
    {
        $this->assertFalse(Config::fromArray([], ['WATCH_URL' => 'https://watch.example.com'])->isEnabled());
        $this->assertFalse(Config::fromArray([], ['WATCH_API_KEY' => self::KEY])->isEnabled());
    }

    public function testOffInTheTestEnvironmentUnlessForced(): void
    {
        $env = ['WATCH_URL' => 'https://watch.example.com', 'WATCH_API_KEY' => self::KEY, 'APP_ENV' => 'testing'];

        $this->assertFalse(Config::fromArray([], $env)->isEnabled());
        $this->assertTrue(Config::fromArray(['enabled' => true], $env)->isEnabled());
        $this->assertFalse(Config::fromArray(['enabled' => false], ['WATCH_URL' => 'https://w.example', 'WATCH_API_KEY' => self::KEY])->isEnabled());
    }

    public function testHttpsRule(): void
    {
        $this->assertTrue(Config::secureUrl('https://watch.example.com', false));
        $this->assertFalse(Config::secureUrl('http://watch.example.com', false));
        $this->assertTrue(Config::secureUrl('http://watch.example.com', true));
        $this->assertTrue(Config::secureUrl('http://localhost:3000', false));
        $this->assertTrue(Config::secureUrl('http://127.0.0.1:3000', false));
        $this->assertTrue(Config::secureUrl('http://127.4.5.6', false));
        $this->assertTrue(Config::secureUrl('http://[::1]:3000', false));
        $this->assertTrue(Config::secureUrl('http://watch.localhost', false));
        $this->assertTrue(Config::secureUrl('http://watch.test', false));
        $this->assertFalse(Config::secureUrl('http://watch.testing.com', false));
        $this->assertFalse(Config::secureUrl('ftp://watch.example.com', true));
        $this->assertFalse(Config::secureUrl('not a url', true));
        $this->assertFalse(Config::secureUrl('', true));
    }

    public function testInsecureUrlKeepsWatchOff(): void
    {
        $config = Config::fromArray([], ['WATCH_URL' => 'http://watch.example.com', 'WATCH_API_KEY' => self::KEY]);
        $this->assertFalse($config->isEnabled());
        $this->assertStringContainsString('https://', implode(' ', $config->problems()));

        $allowed = Config::fromArray([], [
            'WATCH_URL' => 'http://watch.example.com',
            'WATCH_API_KEY' => self::KEY,
            'WATCH_ALLOW_HTTP' => 'true',
        ]);
        $this->assertTrue($allowed->isEnabled());
    }

    public function testDescribeMasksTheKey(): void
    {
        $config = Config::fromArray([], ['WATCH_URL' => 'https://watch.example.com', 'WATCH_API_KEY' => self::KEY]);
        $described = $config->describe();

        $this->assertSame('********1234', $described['api_key']);
        $this->assertStringNotContainsString('example1234', implode(' ', $described));
        $this->assertSame('(not set)', Config::maskKey(null));
    }

    public function testBadNumbersFallBackToDefaults(): void
    {
        $config = Config::fromArray(['batch_size' => 5000, 'timeout' => 'soon'], ['WATCH_FLUSH_INTERVAL' => '2.5']);

        $this->assertSame(1000, $config->batchSize);
        $this->assertSame(5.0, $config->timeout);
        $this->assertSame(2.5, $config->flushInterval);
    }

    public function testUnknownMinLevelIsInfo(): void
    {
        $this->assertSame('info', Config::fromArray(['min_level' => 'loud'], [])->minLevel);
        $this->assertSame('fatal', Config::fromArray(['min_level' => 'critical'], [])->minLevel);
    }
}
