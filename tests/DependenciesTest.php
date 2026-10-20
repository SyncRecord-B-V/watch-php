<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Config;
use SyncRecord\Watch\Dependencies;
use SyncRecord\Watch\Spool;

final class DependenciesTest extends TestCase
{
    private const APP = __DIR__ . '/fixtures/app';

    private string $spoolDir;

    protected function setUp(): void
    {
        $this->spoolDir = sys_get_temp_dir() . '/watch-php-deps-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->spoolDir)) {
            return;
        }
        foreach (scandir($this->spoolDir) ?: [] as $name) {
            if (is_file($this->spoolDir . '/' . $name)) {
                unlink($this->spoolDir . '/' . $name);
            }
        }
        rmdir($this->spoolDir);
    }

    public function testParsesComposerLock(): void
    {
        $packages = Dependencies::packages(self::APP);

        $this->assertSame([
            ['name' => 'acme/internal-tools', 'version' => 'dev-main'],
            ['name' => 'guzzlehttp/guzzle', 'version' => '7.9.2'],
            ['name' => 'laravel/framework', 'version' => '12.3.0'],
            ['name' => 'monolog/monolog', 'version' => '3.8.1'],
        ], $packages);
    }

    public function testParsesInstalledJson(): void
    {
        $packages = Dependencies::parseInstalled((string) file_get_contents(__DIR__ . '/fixtures/installed.json'));

        $this->assertSame([
            ['name' => 'psr/log', 'version' => '3.0.2'],
            ['name' => 'symfony/framework-bundle', 'version' => '7.2.4'],
        ], $packages);
        $this->assertSame(['name' => 'symfony/framework-bundle', 'version' => '7.2.4'], Dependencies::framework($packages));
    }

    public function testParsesComposerOneInstalledJson(): void
    {
        $packages = Dependencies::parseInstalled('[{"name":"slim/slim","version":"4.14.0"}]');

        $this->assertSame([['name' => 'slim/slim', 'version' => '4.14.0']], $packages);
    }

    public function testBrokenFiles(): void
    {
        $this->assertSame([], Dependencies::parseLock('not json'));
        $this->assertSame([], Dependencies::parseInstalled('{"packages": "nope"}'));
        $this->assertSame([], Dependencies::packages(__DIR__ . '/fixtures/missing'));
    }

    public function testVersions(): void
    {
        $this->assertSame('7.9.2', Dependencies::normalizeVersion('v7.9.2'));
        $this->assertSame('2.0.0-RC1', Dependencies::normalizeVersion('V2.0.0-RC1'));
        $this->assertSame('dev-main', Dependencies::normalizeVersion('dev-main'));
        $this->assertSame('vendor-fork', Dependencies::normalizeVersion('vendor-fork'));
    }

    public function testPayload(): void
    {
        $config = Config::fromArray(['root' => self::APP, 'environment' => 'production', 'host' => 'web-1'], []);
        $payload = Dependencies::payload($config);

        $this->assertNotNull($payload);
        $this->assertSame('production', $payload['environment']);
        $this->assertSame('composer', $payload['ecosystem']);
        $this->assertSame(PHP_VERSION, $payload['php']);
        $this->assertSame(['name' => 'laravel/framework', 'version' => '12.3.0'], $payload['framework']);
        $this->assertCount(4, $payload['packages']);
        $this->assertArrayNotHasKey('version', $payload, 'no app version configured');
    }

    public function testDueOncePerChangeOrDay(): void
    {
        $config = Config::fromArray(['root' => self::APP, 'environment' => 'production'], []);
        $spool = new Spool($this->spoolDir);
        $spool->usable();
        $now = 1_800_000_000;

        $payload = Dependencies::due($config, $spool, $now);
        $this->assertNotNull($payload);
        Dependencies::markSent($config, $spool, $payload, $now);

        $this->assertNull(Dependencies::due($config, $spool, $now + 60));
        $this->assertNotNull(Dependencies::due($config, $spool, $now + Dependencies::INTERVAL + 1));
    }

    public function testDigestIgnoresOrder(): void
    {
        $a = ['php' => '8.3.0', 'environment' => 'production', 'packages' => [['name' => 'a/a', 'version' => '1'], ['name' => 'b/b', 'version' => '2']]];
        $b = ['php' => '8.3.0', 'environment' => 'production', 'packages' => [['name' => 'b/b', 'version' => '2'], ['name' => 'a/a', 'version' => '1']]];
        $c = ['php' => '8.4.0', 'environment' => 'production', 'packages' => [['name' => 'a/a', 'version' => '1'], ['name' => 'b/b', 'version' => '2']]];

        $this->assertSame(Dependencies::digest($a), Dependencies::digest($b));
        $this->assertNotSame(Dependencies::digest($a), Dependencies::digest($c));
    }
}
