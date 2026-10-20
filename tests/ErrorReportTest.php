<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Config;
use SyncRecord\Watch\ErrorReport;
use SyncRecord\Watch\Filter;
use SyncRecord\Watch\Request;
use SyncRecord\Watch\Scope;
use SyncRecord\Watch\Watch;

final class ErrorReportTest extends TestCase
{
    private Config $config;
    private Filter $filter;

    protected function setUp(): void
    {
        $this->config = Config::fromArray([
            'url' => 'https://watch.example.com',
            'api_key' => 'lh_example',
            'environment' => 'production',
            'app_version' => 'abc123',
            'host' => 'web-1',
            'root' => dirname(__DIR__),
        ], []);
        $this->filter = new Filter($this->config->filterKeys);
    }

    public function testNoticeForAWebRequest(): void
    {
        $scope = new Scope();
        $scope->isRequest = true;
        $scope->requestId = 'req-1';
        $scope->route = 'App\\Http\\Controllers\\InvoiceController@show';
        $scope->userId = '42';
        $scope->context = ['tenant' => 'acme', 'api_token' => 'secret'];
        $scope->addBreadcrumb(['at' => '2026-10-20T12:00:00.000Z', 'category' => 'query', 'message' => 'SELECT 1'], 40);

        $previous = new \LogicException('inner');
        $error = new \RuntimeException("Invoice \e[31mfailed\e[0m", 0, $previous);
        $notice = ErrorReport::fromThrowable(
            $this->config,
            $this->filter,
            $error,
            $scope,
            $this->request(),
            false,
            ['invoice_id' => 7, 'password' => 'x'],
        );

        $this->assertSame('RuntimeException', $notice['exception_class']);
        $this->assertSame('Invoice failed', $notice['message']);
        $this->assertStringStartsWith('tests/ErrorReportTest.php:', $notice['backtrace'][0]);
        $this->assertMatchesRegularExpression("/\\A[^:]+:\\d+:in '[^']+'\\z/", $notice['backtrace'][0]);
        $this->assertCount(1, $notice['causes']);
        $this->assertSame('LogicException', $notice['causes'][0]['exception_class']);
        $this->assertSame('inner', $notice['causes'][0]['message']);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z\z/', $notice['occurred_at']);
        $this->assertSame('production', $notice['environment']);
        $this->assertSame('web-1', $notice['host']);
        $this->assertSame('abc123', $notice['app_version']);
        $this->assertSame('req-1', $notice['request_id']);
        $this->assertSame('InvoiceController#show', $notice['component']);
        $this->assertFalse($notice['handled']);
        $this->assertSame('42', $notice['user_id']);
        $this->assertSame(['name' => 'watch-php', 'version' => Watch::VERSION, 'language' => 'php'], $notice['notifier']);
        $this->assertSame('SELECT 1', $notice['breadcrumbs'][0]['message']);
        $this->assertSame(['tenant' => 'acme', 'api_token' => '[FILTERED]', 'invoice_id' => 7, 'password' => '[FILTERED]'], $notice['context']);
        $this->assertSame(PHP_VERSION, $notice['app_env']['php_version']);
        $this->assertArrayNotHasKey('fingerprint', $notice);
        $this->assertArrayNotHasKey('job', $notice);
        $this->assertNotEmpty($notice['source']);
        $this->assertSame(0, $notice['source'][0]['index']);

        $request = $notice['request'];
        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://shop.example.com/invoices/42?token=[FILTERED]&page=2', $request['url']);
        $this->assertSame(['token' => '[FILTERED]', 'page' => '2'], $request['params']);
        $this->assertSame('203.0.113.9', $request['remote_ip']);
        $this->assertSame('https://shop.example.com/login?email=[FILTERED]', $request['referrer']);
        $this->assertSame('Mozilla/5.0', $request['user_agent']);
        $this->assertSame('[FILTERED]', $request['headers']['Authorization']);
        $this->assertSame('[FILTERED]', $request['headers']['Cookie']);
        $this->assertSame('[FILTERED]', $request['headers']['X-Forwarded-For']);
        $this->assertSame('[FILTERED]', $request['headers']['X-Custom-Thing']);
        $this->assertSame('Mozilla/5.0', $request['headers']['User-Agent']);
        $this->assertSame('https://shop.example.com/login?email=[FILTERED]', $request['headers']['Referer']);
        $this->assertSame(['session' => '[FILTERED]'], $notice['cookies']);
        $this->assertSame('token=[FILTERED]&page=2', $notice['cgi_env']['QUERY_STRING']);
        $this->assertSame('/invoices/42?token=[FILTERED]&page=2', $notice['cgi_env']['REQUEST_URI']);
        $this->assertSame('[FILTERED]', $notice['cgi_env']['HTTP_AUTHORIZATION']);

        $json = json_encode($notice);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('s3cr3t', $json);
        $this->assertStringNotContainsString('a@example.com', $json);
    }

    public function testNoNullValues(): void
    {
        $notice = ErrorReport::fromThrowable($this->config, $this->filter, new \RuntimeException('x'), new Scope());

        foreach ($notice as $key => $value) {
            $this->assertNotNull($value, $key . ' should be left out instead of null');
        }
        $this->assertTrue($notice['handled']);
        $this->assertArrayNotHasKey('request', $notice);
    }

    public function testJobComponentAndDetails(): void
    {
        $scope = new Scope();
        $scope->jobClass = 'App\\Jobs\\SyncInvoices';
        $scope->jobId = 'job-1';
        $scope->queue = 'default';
        $notice = ErrorReport::fromThrowable($this->config, $this->filter, new \RuntimeException('x'), $scope, $this->request());

        $this->assertSame('App\\Jobs\\SyncInvoices', $notice['component']);
        $this->assertSame(['class' => 'App\\Jobs\\SyncInvoices', 'id' => 'job-1', 'queue' => 'default'], $notice['job']);
    }

    public function testComponentFromThePathWithoutARoute(): void
    {
        $scope = new Scope();
        $notice = ErrorReport::fromThrowable($this->config, $this->filter, new \RuntimeException('x'), $scope, $this->request());

        $this->assertSame('GET /invoices/{id}', $notice['component']);
    }

    public function testFingerprintAndMessageLimit(): void
    {
        $notice = ErrorReport::fromThrowable(
            $this->config,
            $this->filter,
            new \RuntimeException(str_repeat('a', 10000)),
            new Scope(),
            null,
            true,
            [],
            null,
            'my-group',
        );

        $this->assertSame('my-group', $notice['fingerprint']);
        $this->assertSame(8192, strlen($notice['message']));
    }

    public function testFatalError(): void
    {
        $notice = ErrorReport::fromFatal($this->config, $this->filter, [
            'type' => E_ERROR,
            'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
            'file' => dirname(__DIR__) . '/src/Big.php',
            'line' => 12,
        ], new Scope());

        $this->assertSame('E_ERROR', $notice['exception_class']);
        $this->assertSame(['src/Big.php:12'], $notice['backtrace']);
        $this->assertFalse($notice['handled']);
    }

    public function testFatalUncaughtExceptionKeepsItsClass(): void
    {
        $notice = ErrorReport::fromFatal($this->config, $this->filter, [
            'type' => E_ERROR,
            'message' => "Uncaught App\\BoomException: it broke in /var/www/app/src/A.php:3\nStack trace:\n#0 {main}\n  thrown in /var/www/app/src/A.php on line 3",
            'file' => '/var/www/app/src/A.php',
            'line' => 3,
        ], new Scope());

        $this->assertSame('App\\BoomException', $notice['exception_class']);
        $this->assertSame('it broke', $notice['message']);
    }

    public function testAnonymousClassName(): void
    {
        $error = new class ('x') extends \RuntimeException {
        };

        $this->assertStringNotContainsString("\0", ErrorReport::className($error));
        $this->assertStringStartsWith('RuntimeException@anonymous', ErrorReport::className($error));
    }

    public function testEmailFromSetUserIsKept(): void
    {
        $scope = new Scope();
        $scope->userId = '7';
        $scope->userEmail = 'jan@example.com';
        $notice = ErrorReport::fromThrowable($this->config, $this->filter, new \RuntimeException('x'), $scope);

        $this->assertSame(['id' => '7', 'email' => 'jan@example.com'], $notice['context']['user']);
    }

    private function request(): Request
    {
        return new Request(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/invoices/42?token=s3cr3t&page=2',
                'QUERY_STRING' => 'token=s3cr3t&page=2',
                'HTTPS' => 'on',
                'HTTP_HOST' => 'shop.example.com',
                'REMOTE_ADDR' => '203.0.113.9',
                'HTTP_USER_AGENT' => 'Mozilla/5.0',
                'HTTP_REFERER' => 'https://shop.example.com/login?email=a@example.com',
                'HTTP_AUTHORIZATION' => 'Bearer s3cr3t',
                'HTTP_COOKIE' => 'session=s3cr3t',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
                'HTTP_X_CUSTOM_THING' => 's3cr3t',
            ],
            ['token' => 's3cr3t', 'page' => '2'],
            [],
            ['session' => 's3cr3t'],
            true,
        );
    }
}
