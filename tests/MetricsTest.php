<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\ApiQuota;
use SyncRecord\Watch\Config;
use SyncRecord\Watch\Histogram;
use SyncRecord\Watch\Metrics;

final class MetricsTest extends TestCase
{
    public function testRequestsPerEndpoint(): void
    {
        $metrics = new Metrics();
        $metrics->recordRequest('InvoiceController#index', 40, 200, 12.5, 20, 0);
        $metrics->recordRequest('InvoiceController#index', 900, 500, 300);
        $metrics->recordRequest('InvoiceController#index', 10, 404);
        $row = $metrics->rows()['requests'][0];

        $this->assertSame('InvoiceController#index', $row['endpoint']);
        $this->assertSame(3, $row['count']);
        $this->assertSame(1, $row['errors']);
        $this->assertSame(1, $row['client_errors']);
        $this->assertSame(950.0, $row['total_ms']);
        $this->assertSame(900.0, $row['max_ms']);
        $this->assertSame(312.5, $row['db_ms']);
        $this->assertSame(20.0, $row['view_ms']);
        $this->assertCount(20, $row['buckets']);
        $this->assertSame(1, $row['buckets'][Histogram::index(40)]);
        $this->assertSame(1, $row['buckets'][Histogram::index(900)]);
        $this->assertSame(1, $row['buckets'][Histogram::index(10)]);
    }

    public function testOutsideCallsPerHost(): void
    {
        $metrics = new Metrics();
        $metrics->recordHttp('api.exactonline.nl', 120, 200);
        $metrics->recordHttp('api.exactonline.nl', 30, 404);
        $metrics->recordHttp('api.exactonline.nl', 10000, null, 'timeout');
        $row = $metrics->rows()['http'][0];

        $this->assertSame('api.exactonline.nl', $row['host']);
        $this->assertSame(3, $row['count']);
        $this->assertSame(1, $row['errors']);
        $this->assertSame(1, $row['client_errors']);
        $this->assertSame(1, $row['failures']);
        $this->assertSame(1, $row['timeouts']);
        $this->assertSame(0, $row['rate_limited']);
    }

    public function testKeepsTheLatestQuotaAndCounts429s(): void
    {
        $now = (float) gmmktime(12, 0, 0, 10, 15, 2026);
        $metrics = new Metrics();
        $first = ApiQuota::parse(['Sforce-Limit-Info' => 'api-usage=100/1000'], $now);
        $latest = ApiQuota::parse(['Sforce-Limit-Info' => 'api-usage=900/1000'], $now);
        $metrics->recordHttp('x.my.salesforce.com', 10, 200, null, $first);
        $metrics->recordHttp('x.my.salesforce.com', 10, 429, null, $latest);
        $row = $metrics->rows()['http'][0];

        $this->assertSame(1, $row['rate_limited']);
        $this->assertSame(1, $row['client_errors']);
        $this->assertSame([['name' => 'day', 'limit' => 1000, 'remaining' => 100, 'reset_at' => null]], $row['quotas']);
    }

    public function testJobsAndJobHttp(): void
    {
        $metrics = new Metrics();
        $metrics->recordJob('SyncInvoices', 1000.04, false, 'default', 50.0, 300.0, 4, 200.0);
        $metrics->recordJob('SyncInvoices', 3000.0, true, null, 200.0);
        $metrics->recordHttp('api.example.com', 100, 500, null, [], 'SyncInvoices');
        $rows = $metrics->rows();
        $job = $rows['jobs'][0];

        $this->assertSame('SyncInvoices', $job['job_class']);
        $this->assertSame('default', $job['queue']);
        $this->assertSame(2, $job['count']);
        $this->assertSame(1, $job['errors']);
        $this->assertSame(4000.0, $job['total_ms']);
        $this->assertSame(3000.0, $job['max_ms']);
        $this->assertSame(250.0, $job['wait_ms']);
        $this->assertSame(200.0, $job['wait_max_ms']);
        $this->assertSame(4, $job['queries']);
        $this->assertSame(
            ['job_class' => 'SyncInvoices', 'host' => 'api.example.com', 'count' => 1, 'errors' => 1, 'rate_limited' => 0, 'total_ms' => 100.0],
            $rows['job_http'][0],
        );
    }

    public function testAbsorbAddsUpLikeTheHub(): void
    {
        $a = new Metrics();
        $a->recordRequest('HomeController#show', 12, 200);
        $a->recordHttp('api.example.com', 50, 200);
        $b = new Metrics();
        $b->recordRequest('HomeController#show', 300, 503);

        $total = new Metrics();
        $total->absorb($a->rows());
        $total->absorb($b->rows());
        $row = $total->rows()['requests'][0];

        $this->assertSame(2, $row['count']);
        $this->assertSame(1, $row['errors']);
        $this->assertSame(312.0, $row['total_ms']);
        $this->assertSame(300.0, $row['max_ms']);
        $this->assertSame(1, $row['buckets'][Histogram::index(12)]);
        $this->assertSame(1, $row['buckets'][Histogram::index(300)]);
        $this->assertSame(1, $total->rows()['http'][0]['count']);
    }

    public function testListsAreSortedAndCapped(): void
    {
        $metrics = new Metrics();
        for ($i = 0; $i < Metrics::MAX_HOSTS + 20; $i++) {
            $metrics->recordHttp(sprintf('h%03d.example.com', 200 - $i), 1);
        }
        $hosts = array_column($metrics->rows()['http'], 'host');

        $this->assertCount(Metrics::MAX_HOSTS, $hosts);
        $sorted = $hosts;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $hosts);
    }

    public function testPayload(): void
    {
        $config = Config::fromArray(['host' => 'web-1', 'environment' => 'production', 'app_version' => 'abc'], []);
        $metrics = new Metrics();
        $metrics->recordRequest('GET /invoices/{id}', 45.67, 200);
        $payload = $metrics->payload($config, (int) gmmktime(12, 34, 0, 10, 20, 2026));

        $this->assertSame('2026-10-20T12:34:00Z', $payload['sampled_at']);
        $this->assertSame('web-1', $payload['host']);
        $this->assertIsInt($payload['pid']);
        $this->assertSame('production', $payload['environment']);
        $this->assertSame('abc', $payload['version']);
        $this->assertSame(45.7, $payload['requests'][0]['total_ms']);
        $this->assertSame([], $payload['http']);
        $this->assertSame([], $payload['jobs']);
        $this->assertSame([], $payload['job_http']);
        $this->assertStringContainsString('"total_ms":45.7', (string) json_encode($payload, JSON_PRESERVE_ZERO_FRACTION));
    }

    public function testEmptyAndReset(): void
    {
        $metrics = new Metrics();
        $this->assertTrue($metrics->isEmpty());
        $metrics->recordJob('A', 1);
        $this->assertFalse($metrics->isEmpty());
        $metrics->reset();
        $this->assertTrue($metrics->isEmpty());
        $metrics->absorb(['requests' => [['endpoint' => '', 'count' => 1], ['endpoint' => 'X', 'count' => 0], 'junk']]);
        $this->assertTrue($metrics->isEmpty());
    }
}
