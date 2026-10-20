<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Config;
use SyncRecord\Watch\Filter;
use SyncRecord\Watch\Json;

final class JsonTest extends TestCase
{
    public function testSensitiveKeysAreFilteredBeforeTheirValueIsRead(): void
    {
        // A self-referencing array under "password" (it branches, so a full copy would be 2^20 nodes).
        $secret = ['a' => null, 'b' => null];
        $secret['a'] = &$secret;
        $secret['b'] = &$secret;
        $before = memory_get_usage();

        $copy = Json::safe(['user' => 'jane', 'password' => $secret], new Filter(Config::DEFAULT_FILTER_KEYS));

        $this->assertSame(['user' => 'jane', 'password' => '[FILTERED]'], $copy);
        $this->assertLessThan(1024 * 1024, memory_get_usage() - $before);
    }

    public function testACopyNeverGrowsPastItsBudget(): void
    {
        // Same cycle without a filter: depth and node budgets stop it.
        $loop = ['a' => null, 'b' => null];
        $loop['a'] = &$loop;
        $loop['b'] = &$loop;
        $copy = Json::safe(['data' => $loop]);
        $this->assertNotNull(Json::encode($copy));
        $this->assertLessThan(Json::MAX_NODES * 64, strlen((string) Json::encode($copy)));

        $big = array_fill(0, 50000, 'x');
        $copy = Json::safe($big);
        $this->assertLessThanOrEqual(Json::MAX_NODES + 1, count($copy));
        $this->assertSame('[TRUNCATED]', end($copy));

        // Keys count too: "a" (1 byte) plus its value use up the whole text budget.
        $text = Json::safe(['a' => str_repeat('y', Json::MAX_BYTES - 1), 'b' => 'more']);
        $this->assertSame(Json::MAX_BYTES - 1, strlen($text['a']));
        $this->assertArrayNotHasKey('b', $text);
        $this->assertSame('[TRUNCATED]', $text['...']);
    }

    public function testOversizedFieldNamesAreDroppedWithoutCopying(): void
    {
        // A 4 MB field name (uppercase: lowercasing it for the filter would copy it again).
        $name = str_repeat('A', 4 * 1024 * 1024);
        $secret = 'PASSWORD_' . str_repeat('X', Json::MAX_KEY_BYTES);
        $before = memory_get_peak_usage();
        $copy = Json::safe([$name => 'value', $secret => 'hunter2', 'ok' => 1], new Filter(Config::DEFAULT_FILTER_KEYS));

        $this->assertSame([Json::KEY_TOO_LONG => '[TRUNCATED]', 'ok' => 1], $copy);
        $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertStringNotContainsString('hunter2', (string) Json::encode($copy));
        $this->assertSame([Json::KEY_TOO_LONG => '[TRUNCATED]'], (new Filter())->apply([$name => 'value']));
    }

    public function testItemsOfAFilteredListStayFiltered(): void
    {
        $copy = Json::safe(['tokens' => ['t1', 't2'], 'items' => [['token' => 'x', 'qty' => 2]]], new Filter(Config::DEFAULT_FILTER_KEYS));
        $this->assertSame(['tokens' => '[FILTERED]', 'items' => [['token' => '[FILTERED]', 'qty' => 2]]], $copy);
    }
}
