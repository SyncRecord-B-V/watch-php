<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\ApiQuota;

/**
 * Port of watch_client's test/api_quota_test.rb.
 */
final class ApiQuotaTest extends TestCase
{
    private float $now;

    protected function setUp(): void
    {
        $this->now = (float) gmmktime(12, 0, 0, 10, 15, 2026);
    }

    public function testSalesforceDailyUsage(): void
    {
        $this->assertSame(
            [['name' => 'day', 'limit' => 140000, 'remaining' => 133753, 'reset_at' => null]],
            $this->parse(['Sforce-Limit-Info' => 'api-usage=6247/140000']),
        );
    }

    public function testSalesforceOverTheLimitCountsAsNothingLeft(): void
    {
        $this->assertSame(0, $this->parse(['Sforce-Limit-Info' => 'api-usage=146247/140000'])[0]['remaining']);
    }

    public function testGithubStyleWithUnixReset(): void
    {
        $quota = $this->parse([
            'X-RateLimit-Limit' => '5000',
            'X-RateLimit-Remaining' => '4990',
            'X-RateLimit-Reset' => (string) ((int) $this->now + 600),
        ])[0];

        $this->assertSame(['name' => 'default', 'limit' => 5000, 'remaining' => 4990, 'reset_at' => '2026-10-15T12:10:00Z'], $quota);
    }

    public function testIetfHeadersResetInSeconds(): void
    {
        $quota = $this->parse(['RateLimit-Limit' => '100', 'RateLimit-Remaining' => '7', 'RateLimit-Reset' => '30'])[0];

        $this->assertSame('2026-10-15T12:00:30Z', $quota['reset_at']);
        $this->assertSame(7, $quota['remaining']);
    }

    public function testIetfSingleHeader(): void
    {
        $quota = $this->parse(['RateLimit' => 'limit=100, remaining=50, reset=60'])[0];

        $this->assertSame([100, 50, '2026-10-15T12:01:00Z'], [$quota['limit'], $quota['remaining'], $quota['reset_at']]);
    }

    public function testExactOnlineMinuteAndDayWithMillisecondResets(): void
    {
        $quotas = $this->parse([
            'X-RateLimit-Minutely-Limit' => '60',
            'X-RateLimit-Minutely-Remaining' => '12',
            'X-RateLimit-Minutely-Reset' => (string) (((int) $this->now + 30) * 1000),
            'X-RateLimit-Limit' => '5000',
            'X-RateLimit-Remaining' => '4100',
            'X-RateLimit-Reset' => (string) (((int) $this->now + 3600) * 1000),
        ]);
        $remaining = array_column($quotas, 'remaining', 'name');
        $resets = array_column($quotas, 'reset_at', 'name');

        $this->assertSame(['minute' => 12, 'day' => 4100], $remaining);
        $this->assertSame('2026-10-15T12:00:30Z', $resets['minute']);
    }

    public function testShopifyBucketAndHubspotDaily(): void
    {
        $this->assertSame(8, $this->parse(['X-Shopify-Shop-Api-Call-Limit' => '32/40'])[0]['remaining']);
        $this->assertSame(
            ['name' => 'day', 'limit' => 250000, 'remaining' => 1000, 'reset_at' => null],
            $this->parse(['X-HubSpot-RateLimit-Daily' => '250000', 'X-HubSpot-RateLimit-Daily-Remaining' => '1000'])[0],
        );
    }

    public function testIgnoresMissingOrOddValues(): void
    {
        $this->assertSame([], $this->parse([]));
        $this->assertSame([], $this->parse(['X-RateLimit-Limit' => 'lots', 'X-RateLimit-Remaining' => '5']));
        $this->assertSame([], $this->parse(['X-RateLimit-Limit' => '0', 'X-RateLimit-Remaining' => '0']));
        $this->assertSame([], $this->parse(['Sforce-Limit-Info' => 'api-usage=abc']));
        $this->assertNull($this->parse([
            'X-RateLimit-Limit' => '10',
            'X-RateLimit-Remaining' => '5',
            'X-RateLimit-Reset' => 'not a date',
        ])[0]['reset_at']);
        $this->assertSame([], ApiQuota::parse(null));
    }

    public function testOversizedOrOddHeadersAreIgnoredBeforeParsing(): void
    {
        $huge = str_repeat('limit=100, remaining=5, ', 150000);
        $fields = implode(', ', array_map(static fn (int $i): string => 'x' . $i . '=1', range(0, 49)));

        $this->assertSame([], $this->parse(['RateLimit' => $huge]));
        $this->assertSame([], $this->parse(['Sforce-Limit-Info' => 'api-usage=1/2' . str_repeat(' ', 300)]));
        $this->assertSame([], $this->parse(['X-RateLimit-Limit' => str_repeat('9', 40), 'X-RateLimit-Remaining' => '5']));
        $this->assertSame([], $this->parse(['RateLimit' => $fields]));
        $this->assertSame(50, $this->parse(['RateLimit' => 'limit=100, remaining=50'])[0]['remaining']);
    }

    public function testPsr7StyleHeaderLists(): void
    {
        $quota = $this->parse(['x-ratelimit-limit' => ['60'], 'x-ratelimit-remaining' => ['59']])[0];

        $this->assertSame(['name' => 'default', 'limit' => 60, 'remaining' => 59, 'reset_at' => null], $quota);
    }

    public function testHttpDateReset(): void
    {
        $quota = $this->parse([
            'X-RateLimit-Limit' => '10',
            'X-RateLimit-Remaining' => '5',
            'X-RateLimit-Reset' => 'Thu, 15 Oct 2026 13:00:00 GMT',
        ])[0];

        $this->assertSame('2026-10-15T13:00:00Z', $quota['reset_at']);
    }

    public function testAtMostFourQuotas(): void
    {
        $quotas = $this->parse([
            'RateLimit-Limit' => '1', 'RateLimit-Remaining' => '1',
            'X-RateLimit-Minutely-Limit' => '2', 'X-RateLimit-Minutely-Remaining' => '2',
            'X-RateLimit-Limit' => '3', 'X-RateLimit-Remaining' => '3',
            'X-HubSpot-RateLimit-Daily' => '4', 'X-HubSpot-RateLimit-Daily-Remaining' => '4',
            'X-Shopify-Shop-Api-Call-Limit' => '1/5',
        ]);

        $this->assertSame(['default', 'minute', 'day', 'bucket'], array_column($quotas, 'name'));
    }

    /**
     * @param array<string, mixed> $headers
     * @return list<array{name: string, limit: int, remaining: int, reset_at: ?string}>
     */
    private function parse(array $headers): array
    {
        return ApiQuota::parse($headers, $this->now);
    }
}
