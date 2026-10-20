<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Config;
use SyncRecord\Watch\Filter;
use SyncRecord\Watch\LogEntry;
use SyncRecord\Watch\Scope;

final class LogEntryTest extends TestCase
{
    private Config $config;
    private Filter $filter;

    protected function setUp(): void
    {
        $this->config = Config::fromArray(['environment' => 'production', 'host' => 'web-1', 'app_version' => 'v42', 'root' => '/var/www/app'], []);
        $this->filter = new Filter($this->config->filterKeys);
    }

    public function testBasicEntry(): void
    {
        $time = (float) gmmktime(12, 34, 56, 10, 20, 2026) + 0.123456;
        $entry = LogEntry::build($this->config, $this->filter, 'info', 'Invoice sent', ['invoice_id' => 7], new Scope(), $time);

        $this->assertSame([
            'timestamp' => '2026-10-20T12:34:56.123456Z',
            'level' => 'info',
            'message' => 'Invoice sent',
            'environment' => 'production',
            'host' => 'web-1',
            'app_version' => 'v42',
            'invoice_id' => 7,
        ], $entry);
    }

    public function testPsr3Levels(): void
    {
        $this->assertSame('info', LogEntry::levelName('notice'));
        $this->assertSame('warn', LogEntry::levelName('warning'));
        $this->assertSame('fatal', LogEntry::levelName('critical'));
        $this->assertSame('fatal', LogEntry::levelName('alert'));
        $this->assertSame('fatal', LogEntry::levelName('emergency'));
        $this->assertSame('error', LogEntry::levelName('ERROR'));
        $this->assertSame('debug', LogEntry::levelName('debug'));
        $this->assertSame('warn', LogEntry::levelName(2));
        $this->assertSame('unknown', LogEntry::levelName(9));
        $this->assertSame('info', LogEntry::levelName('chatty'));
        $this->assertSame(4, LogEntry::levelIndex('emergency'));
    }

    public function testPlaceholdersUseFilteredValues(): void
    {
        $entry = LogEntry::build(
            $this->config,
            $this->filter,
            'warning',
            'User {user} reset password {password} ({missing})',
            ['user' => 'jan', 'password' => 'hunter2'],
            new Scope(),
        );

        $this->assertSame('User jan reset password [FILTERED] ({missing})', $entry['message']);
        $this->assertSame('[FILTERED]', $entry['password']);
        $this->assertSame('warn', $entry['level']);
        $this->assertStringNotContainsString('hunter2', (string) json_encode($entry));
    }

    public function testExceptionInContext(): void
    {
        $error = new \RuntimeException('disk full');
        $entry = LogEntry::build($this->config, $this->filter, 'error', 'Export failed', ['exception' => $error], new Scope());

        $this->assertSame('RuntimeException', $entry['exception']['class']);
        $this->assertSame('disk full', $entry['exception']['message']);
        $this->assertStringContainsString('LogEntryTest.php:', $entry['exception']['location']);
    }

    public function testFieldsNeverOverwriteCoreColumns(): void
    {
        $scope = new Scope();
        $scope->requestId = 'req-1';
        $scope->userId = '9';
        $entry = LogEntry::build($this->config, $this->filter, 'info', 'x', ['level' => 'fatal', 'request_id' => 'other', 'job_id' => 'j1'], $scope);

        $this->assertSame('info', $entry['level']);
        $this->assertSame('req-1', $entry['request_id']);
        $this->assertSame('9', $entry['user_id']);
        $this->assertSame('j1', $entry['job_id']);
    }

    public function testJobScopeFields(): void
    {
        $scope = new Scope();
        $scope->jobClass = 'SendInvoice';
        $scope->jobId = 'abc';
        $scope->queue = 'mail';
        $scope->userId = '9';
        $entry = LogEntry::build($this->config, $this->filter, 'info', 'x', [], $scope);

        $this->assertSame('SendInvoice', $entry['job_class']);
        $this->assertSame('abc', $entry['job_id']);
        $this->assertSame('mail', $entry['queue']);
        $this->assertArrayNotHasKey('user_id', $entry, 'user_id only inside a request');
    }

    public function testAnsiColoursAndLongMessages(): void
    {
        $entry = LogEntry::build($this->config, $this->filter, 'info', "\e[32m ok \e[0m", [], new Scope());
        $this->assertSame('ok', $entry['message']);

        $long = LogEntry::build($this->config, $this->filter, 'info', str_repeat('x', 40000), [], new Scope());
        $this->assertSame(LogEntry::MAX_MESSAGE_BYTES, strlen($long['message']));
    }

    public function testLargeFieldsAreTruncated(): void
    {
        $entry = LogEntry::build($this->config, $this->filter, 'info', 'x', [
            'big' => str_repeat('a', 9000),
            'big2' => str_repeat('b', 9000),
            'big3' => str_repeat('c', 9000),
            'big4' => str_repeat('d', 9000),
            'big5' => str_repeat('e', 9000),
            'big6' => str_repeat('f', 9000),
            'big7' => str_repeat('g', 9000),
            'big8' => str_repeat('h', 9000),
            'small' => 'ok',
        ], new Scope());

        $this->assertSame('[TRUNCATED: 9002 bytes]', $entry['big']);
        $this->assertSame('ok', $entry['small']);
        $this->assertLessThanOrEqual(LogEntry::MAX_ENTRY_BYTES, strlen((string) json_encode($entry)));
    }

    public function testObjectsAreMadeSafe(): void
    {
        $entry = LogEntry::build($this->config, $this->filter, 'info', 'x', [
            'when' => new \DateTimeImmutable('2026-10-20T12:00:00+00:00'),
            'nan' => NAN,
            'thing' => new \ArrayObject(),
        ], new Scope());

        $this->assertSame('2026-10-20T12:00:00.000000+00:00', $entry['when']);
        $this->assertSame('NAN', $entry['nan']);
        $this->assertSame('[object ArrayObject]', $entry['thing']);
        $this->assertIsString(json_encode($entry));
    }
}
