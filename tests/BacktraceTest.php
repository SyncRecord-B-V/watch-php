<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Backtrace;

final class BacktraceTest extends TestCase
{
    private const ROOT = '/var/www/app';

    private int $exceptionLine = 0;

    public function testFramesFromAnException(): void
    {
        $root = dirname(__DIR__);
        $line = __LINE__ + 1;
        $error = $this->makeException();
        $lines = Backtrace::fromThrowable($error, $root);

        $this->assertSame(
            "tests/BacktraceTest.php:" . $this->exceptionLine . ":in 'SyncRecord\\Watch\\Tests\\BacktraceTest->makeException'",
            $lines[0],
        );
        $this->assertSame(
            "tests/BacktraceTest.php:" . $line . ":in 'SyncRecord\\Watch\\Tests\\BacktraceTest->testFramesFromAnException'",
            $lines[1],
        );
        $this->assertStringEndsWith(":in '{main}'", $lines[count($lines) - 1]);
    }

    public function testFormatOfSyntheticFrames(): void
    {
        $trace = [
            ['file' => '/var/www/app/src/Billing/Invoice.php', 'line' => 30, 'function' => 'total', 'class' => 'App\\Billing\\Invoice', 'type' => '->'],
            ['file' => '/var/www/app/src/Controller/InvoiceController.php', 'line' => 12, 'function' => 'create', 'class' => 'App\\Billing\\Factory', 'type' => '::'],
            ['file' => '/var/www/app/vendor/acme/router/src/Router.php', 'line' => 99, 'function' => '{closure}', 'class' => 'App\\Controller\\InvoiceController', 'type' => '->'],
            ['function' => 'array_map'],
            ['file' => '/var/www/app/public/index.php', 'line' => 5, 'function' => 'dispatch'],
        ];

        $lines = Backtrace::fromFrames('/var/www/app/src/Billing/Invoice.php', 42, $trace, self::ROOT);

        $this->assertSame([
            "src/Billing/Invoice.php:42:in 'App\\Billing\\Invoice->total'",
            "src/Billing/Invoice.php:30:in 'App\\Billing\\Factory::create'",
            "src/Controller/InvoiceController.php:12:in 'App\\Controller\\InvoiceController->{closure}'",
            "/var/www/app/vendor/acme/router/src/Router.php:99:in 'array_map'",
            "[internal]:0:in 'dispatch'",
            "public/index.php:5:in '{main}'",
        ], $lines);
    }

    public function testTopLevelCode(): void
    {
        $this->assertSame(
            ["public/index.php:3:in '{main}'"],
            Backtrace::fromFrames('/var/www/app/public/index.php', 3, [], self::ROOT),
        );
    }

    public function testPhp84ClosureNamesLoseTheirLineNumber(): void
    {
        $this->assertSame(
            'App\\Jobs\\Sync->{closure}',
            Backtrace::label(['function' => '{closure:App\\Jobs\\Sync::handle():12}', 'class' => 'App\\Jobs\\Sync', 'type' => '->']),
        );
        $this->assertSame('{closure}', Backtrace::label(['function' => '{closure:/var/www/app/routes/web.php:7}']));
    }

    public function testMaxLines(): void
    {
        $trace = array_fill(0, 50, ['file' => '/var/www/app/src/A.php', 'line' => 1, 'function' => 'f']);

        $this->assertCount(10, Backtrace::fromFrames('/var/www/app/src/A.php', 1, $trace, self::ROOT, 10));
    }

    public function testRelativePaths(): void
    {
        $this->assertSame('src/A.php', Backtrace::relative('/var/www/app/src/A.php', self::ROOT));
        $this->assertSame('/var/www/app/vendor/x/y/A.php', Backtrace::relative('/var/www/app/vendor/x/y/A.php', self::ROOT));
        $this->assertSame('/usr/share/php/A.php', Backtrace::relative('/usr/share/php/A.php', self::ROOT));
        $this->assertSame('/var/www/application/A.php', Backtrace::relative('/var/www/application/A.php', self::ROOT));
        $this->assertSame('/var/www/app/A.php', Backtrace::relative('/var/www/app/A.php', ''));
    }

    private function makeException(): \RuntimeException
    {
        $this->exceptionLine = __LINE__ + 1;

        return new \RuntimeException('boom');
    }
}
