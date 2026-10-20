<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Histogram;

/**
 * Same expectations as the hub (spec/models/histogram_spec.rb) and watch_client
 * (test/performance_test.rb): bucket i counts ms <= BOUNDS[i], the last one everything slower.
 */
final class HistogramTest extends TestCase
{
    public function testBucketIndexes(): void
    {
        $this->assertSame(0, Histogram::index(3));
        $this->assertSame(0, Histogram::index(5));
        $this->assertSame(1, Histogram::index(5.1));
        $this->assertSame(1, Histogram::index(5.01));
        $this->assertSame(19, Histogram::index(60000));
        $this->assertSame(count(Histogram::BOUNDS), Histogram::index(60000));
        $this->assertSame(20, count(Histogram::empty()));
    }

    public function testIndexesUsedByTheHubSpec(): void
    {
        $this->assertSame(5, Histogram::index(80));   // 75 < x <= 100
        $this->assertSame(11, Histogram::index(900)); // 750 < x <= 1000
    }

    public function testEdges(): void
    {
        $this->assertSame(0, Histogram::index(0));
        $this->assertSame(0, Histogram::index(-12));
        $this->assertSame(18, Histogram::index(20000));
        $this->assertSame(19, Histogram::index(20000.5));
        $this->assertSame(17, Histogram::index(10000));
        $this->assertSame(18, Histogram::index(10000.01));
        foreach (Histogram::BOUNDS as $index => $bound) {
            $this->assertSame($index, Histogram::index($bound));
        }
    }

    public function testAdd(): void
    {
        $a = Histogram::add([1, 2], []);
        $sum = Histogram::add($a, [3]);

        $this->assertSame(4, $sum[0]);
        $this->assertSame(2, $sum[1]);
        $this->assertCount(20, $sum);
        $this->assertSame(2147483647, Histogram::add([2147483647], [5])[0]);
        $this->assertSame(0, Histogram::add([-3], ['x'])[0]);
    }
}
