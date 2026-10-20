<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Client;
use SyncRecord\Watch\Config;
use SyncRecord\Watch\Request;
use SyncRecord\Watch\Result;
use SyncRecord\Watch\Spool;
use SyncRecord\Watch\Tests\Fake\FakeTransport;
use SyncRecord\Watch\Transport;

final class ClientTest extends TestCase
{
    private string $dir;
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/watch-php-client-' . bin2hex(random_bytes(6));
        $this->transport = new FakeTransport();
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, $this->dir . '-state'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $name) {
                if (is_file($dir . '/' . $name)) {
                    unlink($dir . '/' . $name);
                }
            }
            rmdir($dir);
        }
    }

    public function testCliSendsErrorsAtOnce(): void
    {
        $client = $this->cliClient();
        $client->notify(new \RuntimeException('boom'), ['order' => 5]);

        $errors = $this->transport->payloads(Transport::ERRORS);
        $this->assertCount(1, $errors);
        $this->assertSame('boom', $errors[0]['errors'][0]['message']);
        $this->assertSame(['order' => 5], $errors[0]['errors'][0]['context']);
    }

    public function testTheSameExceptionIsReportedOnce(): void
    {
        $client = $this->cliClient();
        $error = new \RuntimeException('once');
        $client->notify($error);
        $client->notify($error);

        $this->assertCount(1, $this->transport->payloads(Transport::ERRORS));
    }

    public function testIgnoredExceptions(): void
    {
        $client = $this->cliClient(['ignore_errors' => [\DomainException::class]]);
        $client->notify(new \DomainException('ignored'));

        $this->assertSame([], $this->transport->posts);
    }

    public function testBeforeNotifyCanChangeOrDrop(): void
    {
        $client = $this->cliClient(['before_notify' => static function (array $notice): ?array {
            if ($notice['message'] === 'drop me') {
                return null;
            }
            $notice['fingerprint'] = 'custom';

            return $notice;
        }]);
        $client->notify(new \RuntimeException('drop me'));
        $client->notify(new \RuntimeException('keep me'));

        $errors = $this->transport->payloads(Transport::ERRORS);
        $this->assertCount(1, $errors);
        $this->assertSame('custom', $errors[0]['errors'][0]['fingerprint']);
    }

    public function testCliLogsAreBufferedUntilFlush(): void
    {
        $client = $this->cliClient(['min_level' => 'info']);
        $client->log('debug', 'skipped');
        $client->log('info', 'first');
        $client->log('warning', 'second {n}', ['n' => 2]);

        $this->assertSame([], $this->transport->posts);
        $client->flush();

        $logs = $this->transport->payloads(Transport::LOGS);
        $this->assertCount(1, $logs);
        $this->assertSame(['first', 'second 2'], array_column($logs[0]['entries'], 'message'));
        $this->assertSame(['info', 'warn'], array_column($logs[0]['entries'], 'level'));
    }

    public function testBatchSizeTriggersAFlush(): void
    {
        $client = $this->cliClient(['batch_size' => 2]);
        $client->log('info', 'a');
        $client->log('info', 'b');

        $this->assertCount(1, $this->transport->payloads(Transport::LOGS));
    }

    public function testJobsAreTimedAndFailuresReported(): void
    {
        $client = $this->cliClient();

        $this->assertSame(42, $client->job('App\\Jobs\\Ok', static fn (): int => 42, 'default'));
        try {
            $client->job('App\\Jobs\\Broken', static function (): void {
                throw new \RuntimeException('job failed');
            });
            $this->fail('the exception should be thrown again');
        } catch (\RuntimeException $error) {
            $this->assertSame('job failed', $error->getMessage());
        }

        $errors = $this->transport->payloads(Transport::ERRORS);
        $this->assertSame('App\\Jobs\\Broken', $errors[0]['errors'][0]['component']);
        $this->assertFalse($errors[0]['errors'][0]['handled']);
        $this->assertSame('App\\Jobs\\Broken', $errors[0]['errors'][0]['job']['class']);

        $client->flush();
        $metrics = $this->transport->payloads(Transport::METRICS);
        $jobs = array_column($metrics[0]['jobs'], null, 'job_class');
        $this->assertSame(1, $jobs['App\\Jobs\\Ok']['count']);
        $this->assertSame(0, $jobs['App\\Jobs\\Ok']['errors']);
        $this->assertSame('default', $jobs['App\\Jobs\\Ok']['queue']);
        $this->assertSame(1, $jobs['App\\Jobs\\Broken']['errors']);
    }

    public function testHttpCallsInsideAJobAreCountedForTheJob(): void
    {
        $client = $this->cliClient();
        $client->job('SyncJob', static function () use ($client): void {
            $client->recordHttp('API.example.com', 120.0, 429, ['Retry-After' => '1']);
        });
        $client->flush();

        $payload = $this->transport->payloads(Transport::METRICS)[0];
        $this->assertSame('api.example.com', $payload['http'][0]['host']);
        $this->assertSame(1, $payload['http'][0]['rate_limited']);
        $this->assertSame('SyncJob', $payload['job_http'][0]['job_class']);
        $this->assertSame(120.0, $payload['jobs'][0]['http_ms']);
    }

    public function testPhpWarningsBecomeOneLogLinePerPlace(): void
    {
        $client = $this->cliClient();
        $client->recordPhpError(E_WARNING, 'Undefined variable $x', '/var/www/app/src/A.php', 3);
        $client->recordPhpError(E_WARNING, 'Undefined variable $x', '/var/www/app/src/A.php', 3);
        $client->recordPhpError(E_DEPRECATED, 'Old thing', '/var/www/app/src/B.php', 9);

        $logs = $client->pendingLogs();
        $this->assertCount(2, $logs);
        $this->assertSame('Warning: Undefined variable $x', $logs[0]['message']);
        $this->assertSame('warn', $logs[0]['level']);
        $this->assertSame('E_WARNING', $logs[0]['php_error']);
        $this->assertSame('src/A.php:3', $logs[0]['location']);
        $this->assertSame('log', $client->scope()->breadcrumbs[0]['category']);
    }

    public function testWebRequestGoesToTheSpool(): void
    {
        $client = $this->webClient();
        $client->setRoute('App\\Http\\Controllers\\InvoiceController@show');
        $client->log('info', 'hello');
        $client->notify(new \RuntimeException('web error'));
        $client->shutdown();

        $this->assertCount(1, $this->transport->payloads(Transport::ERRORS), 'errors are sent at the end of the request');
        $this->assertSame([], $this->transport->payloads(Transport::LOGS), 'logs wait in the spool');
        $spool = new Spool($this->dir);
        $files = glob($this->dir . '/*.jsonl') ?: [];
        $this->assertCount(2, $files);
        $metricsFiles = glob($this->dir . '/metrics-*.jsonl') ?: [];
        $this->assertCount(1, $metricsFiles);
        $record = $spool->read($metricsFiles[0])[0];
        $this->assertSame('InvoiceController#show', $record['requests'][0]['endpoint']);
        $this->assertSame(1, $record['requests'][0]['count']);
    }

    public function testDueSpoolFilesAreSentAndDeleted(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();
        $past = time() - 180;
        $spool->append('logs', [['message' => 'a'], ['message' => 'b']], $past);
        $spool->append('metrics', [
            ['requests' => [['endpoint' => 'GET /', 'count' => 1, 'total_ms' => 10.0, 'max_ms' => 10.0]]],
            ['requests' => [['endpoint' => 'GET /', 'count' => 2, 'total_ms' => 30.0, 'max_ms' => 20.0]]],
        ], $past);

        $client = $this->webClient();
        $this->assertTrue($client->sendSpool());

        $metrics = $this->transport->payloads(Transport::METRICS);
        $this->assertCount(1, $metrics);
        $this->assertSame(3, $metrics[0]['requests'][0]['count']);
        $this->assertSame(40.0, $metrics[0]['requests'][0]['total_ms']);
        $this->assertSame(20.0, $metrics[0]['requests'][0]['max_ms']);
        $this->assertSame(gmdate('Y-m-d\TH:i:00\Z', $past), $metrics[0]['sampled_at']);
        $this->assertSame(['a', 'b'], array_column($this->transport->payloads(Transport::LOGS)[0]['entries'], 'message'));
        $this->assertSame([], $spool->dueFiles('logs'));
        $this->assertSame([], $spool->dueFiles('metrics'));
    }

    public function testFailedSendKeepsTheFileAndWaits(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();
        $spool->append('logs', [['message' => 'a']], time() - 180);
        $this->transport->willReturn(Result::retry('HTTP 503', 503));

        $client = $this->webClient();
        $this->assertFalse($client->sendSpool());
        $this->assertCount(1, $spool->dueFiles('logs'));
        $this->assertFalse($spool->sendAllowed());
    }

    public function testMetricsAreNotResentAfterATimeout(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();
        $spool->append('metrics', [['requests' => [['endpoint' => 'GET /', 'count' => 1]]]], time() - 180);
        $this->transport->willReturn(Result::retry('timed out', 0, true));

        $this->assertTrue($this->webClient()->sendSpool());
        $this->assertCount(1, $this->transport->payloads(Transport::METRICS));
        $this->assertSame([], $spool->dueFiles('metrics'));
    }

    public function testConcurrentRequestsKeepTheirOwnUserAndUrl(): void
    {
        $context = 'a';
        Client::resolveContextWith(static function () use (&$context): string {
            return $context;
        });
        try {
            $client = $this->cliClient();
            $client->beginRequest($this->httpRequest('/orders/1'));
            $client->setUser('user-a');
            $client->addContext(['tenant' => 'a']);

            $context = 'b';
            $client->beginRequest($this->httpRequest('/invoices/2'));
            $client->setUser('user-b');
            $client->addContext(['tenant' => 'b']);

            $context = 'a';
            $client->notify(new \RuntimeException('in request a'));
            $context = 'c'; // a context that never began a request: no one else's data
            $client->notify(new \RuntimeException('outside any request'));
        } finally {
            Client::resolveContextWith(null);
        }

        $errors = array_merge(...array_column($this->transport->payloads(Transport::ERRORS), 'errors'));
        $this->assertCount(2, $errors);
        $this->assertSame('user-a', $errors[0]['user_id']);
        $this->assertStringContainsString('/orders/1', $errors[0]['request']['url']);
        $this->assertSame(['tenant' => 'a'], $errors[0]['context']);
        $this->assertArrayNotHasKey('user_id', array_filter($errors[1], static fn ($value): bool => $value !== null));
        $this->assertArrayNotHasKey('request', array_filter($errors[1], static fn ($value): bool => $value !== null));
    }

    public function testFinishedRequestContextsAreReleased(): void
    {
        $context = 'main-loop';
        Client::resolveContextWith(static function () use (&$context): string {
            return $context;
        });
        try {
            $client = $this->cliClient();
            $before = $client->contextCount();
            for ($i = 0; $i < 1000; $i++) {
                $context = 'request-' . $i;
                $client->beginRequest($this->httpRequest('/orders/' . $i));
                try {
                    $client->setUser('user-' . $i);
                    if ($i % 2 === 0) {
                        throw new \RuntimeException('request ' . $i . ' failed');
                    }
                    $client->endRequest(200);
                } catch (\RuntimeException) {
                    $client->endRequest(500);
                } finally {
                    $client->releaseContext();
                }
            }

            $this->assertSame($before, $client->contextCount());

            // Code that runs after the release (an outer error handler logging) doesn't store a new state.
            $client->log('error', 'logged by an outer handler');
            $client->setUser('late');
            $this->assertSame($before, $client->contextCount());
        } finally {
            Client::resolveContextWith(null);
        }
    }

    public function testAWorkerForgetsTheFinishedRequest(): void
    {
        $client = $this->cliClient();
        $client->beginRequest($this->httpRequest('/orders/1'));
        $client->setUser('user-1');
        $client->endRequest(200);
        $client->releaseContext();
        $client->notify(new \RuntimeException('between requests'));

        $errors = array_merge(...array_column($this->transport->payloads(Transport::ERRORS), 'errors'));
        $this->assertCount(1, $errors);
        $this->assertNull($errors[0]['user_id'] ?? null);
        $this->assertNull($errors[0]['request'] ?? null);
    }

    public function testSpoolSendingStopsAtTheBudgetAndKeepsTheRest(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();
        $records = [];
        for ($i = 0; $i < 5; $i++) {
            $records[] = ['message' => 'line ' . $i];
        }
        $spool->append('logs', $records, time() - 180);

        $now = 1000.0;
        $this->transport->onPost = static function () use (&$now): void {
            $now += 6.0; // every send takes 6 seconds
        };
        $config = Config::fromArray([
            'url' => 'https://watch.example.com', 'api_key' => 'lh_example', 'environment' => 'production',
            'root' => '/var/www/app', 'spool_dir' => $this->dir, 'report_dependencies' => false,
            'finish_request' => false, 'batch_size' => 1,
        ], []);
        $client = new Client($config, $this->transport, new Spool($this->dir), $this->httpRequest('/'), false,
            static function () use (&$now): float {
                return $now;
            });

        $this->assertTrue($client->sendSpool());
        // 15 s budget, 6 s per batch: 3 batches start (at 0, 6 and 12 s), never a fourth.
        $sent = $this->transport->payloads(Transport::LOGS);
        $this->assertCount(3, $sent);
        $this->assertLessThan(15.0, $this->transport->posts[2]['timeout'] ?? 99.0);
        // The rest stays in the spool for the next request.
        $files = $spool->dueFiles('logs');
        $this->assertCount(1, $files);
        $this->assertSame(['line 3', 'line 4'], array_column($spool->read($files[0]), 'message'));
    }

    public function testCliAppsReportDependenciesWithoutASpool(): void
    {
        $config = Config::fromArray([
            'url' => 'https://watch.example.com', 'api_key' => 'lh_example', 'environment' => 'production',
            'root' => __DIR__ . '/fixtures/app', 'spool_dir' => $this->dir, 'report_dependencies' => true,
        ], []);
        // The CLI never creates the spool directory ($this->dir doesn't exist).
        $cli = new Request(['argv' => ['worker.php']], [], [], [], false);
        $client = new Client($config, $this->transport, new Spool($this->dir), $cli, true);
        $client->log('error', 'first job failed');
        $client->flush();
        $client->flush();

        $this->assertCount(1, $this->transport->payloads(Transport::DEPENDENCIES));
        $this->assertSame('composer', $this->transport->payloads(Transport::DEPENDENCIES)[0]['ecosystem']);
        $this->assertDirectoryDoesNotExist($this->dir);

        // The next run of the script (a new process) remembers it: a cron job doesn't report every run.
        $nextRun = new Client($config, $this->transport, new Spool($this->dir), $cli, true);
        $nextRun->flush();
        $this->assertCount(1, $this->transport->payloads(Transport::DEPENDENCIES));
        $this->assertDirectoryExists($this->dir . '-state');
    }

    private function httpRequest(string $uri): Request
    {
        return new Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'shop.example.com'], [], [], [], true);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function cliClient(array $options = []): Client
    {
        $config = Config::fromArray($options + [
            'url' => 'https://watch.example.com',
            'api_key' => 'lh_example',
            'environment' => 'production',
            'root' => '/var/www/app',
            'spool_dir' => $this->dir,
            'report_dependencies' => false,
        ], []);

        return new Client($config, $this->transport, new Spool($this->dir), new Request(['argv' => ['worker.php']], [], [], [], false), true);
    }

    private function webClient(): Client
    {
        $config = Config::fromArray([
            'url' => 'https://watch.example.com',
            'api_key' => 'lh_example',
            'environment' => 'production',
            'root' => '/var/www/app',
            'spool_dir' => $this->dir,
            'report_dependencies' => false,
            'finish_request' => false,
        ], []);
        $request = new Request([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/invoices/42',
            'HTTP_HOST' => 'shop.example.com',
        ], [], [], [], true);

        return new Client($config, $this->transport, new Spool($this->dir), $request, false);
    }
}
