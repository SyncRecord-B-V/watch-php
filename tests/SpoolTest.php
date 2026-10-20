<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Spool;

final class SpoolTest extends TestCase
{
    private string $dir;
    private int $minute;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/watch-php-test-' . bin2hex(random_bytes(6));
        $this->minute = (int) gmmktime(12, 34, 0, 10, 20, 2026);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach (scandir($this->dir) ?: [] as $name) {
            if (is_file($this->dir . '/' . $name)) {
                unlink($this->dir . '/' . $name);
            }
        }
        rmdir($this->dir);
    }

    public function testCreatesTheDirectory(): void
    {
        $spool = new Spool($this->dir);

        $this->assertFalse($spool->usable(false));
        $this->assertTrue($spool->usable());
        $this->assertDirectoryExists($this->dir);
    }

    public function testAppendAndReadPerMinute(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();
        $this->assertTrue($spool->append('logs', [['message' => 'a'], ['message' => "line\nbreak"]], $this->minute + 5));
        $this->assertTrue($spool->append('logs', [['message' => 'c']], $this->minute + 50));
        $this->assertTrue($spool->append('logs', [['message' => 'next minute']], $this->minute + 65));

        $path = $spool->path('logs', $this->minute);
        $this->assertSame($this->dir . '/logs-202610201234.jsonl', $path);
        $this->assertSame([['message' => 'a'], ['message' => "line\nbreak"], ['message' => 'c']], $spool->read($path));
        $this->assertSame($this->minute, Spool::minuteOf($path));
    }

    public function testOnlyFinishedMinutesAreDue(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();
        $spool->append('metrics', [['requests' => []]], $this->minute);
        $spool->append('logs', [['message' => 'x']], $this->minute + 60);

        $this->assertSame([], $spool->dueFiles('metrics', $this->minute + 59));
        $this->assertSame([], $spool->dueFiles('metrics', $this->minute + 61), 'waits a moment for late writers');
        $this->assertSame([$spool->path('metrics', $this->minute)], $spool->dueFiles('metrics', $this->minute + 62));
        $this->assertSame([], $spool->dueFiles('logs', $this->minute + 62));
        $this->assertTrue($spool->hasDue($this->minute + 62));
        $this->assertFalse($spool->hasDue($this->minute + 10));
    }

    public function testDueFilesOldestFirst(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();
        $spool->append('logs', [['message' => 'later']], $this->minute + 120);
        $spool->append('logs', [['message' => 'earlier']], $this->minute);

        $this->assertSame(
            [$spool->path('logs', $this->minute), $spool->path('logs', $this->minute + 120)],
            $spool->dueFiles('logs', $this->minute + 600),
        );
    }

    public function testRewriteAndDelete(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();
        $spool->append('logs', [['message' => 'a'], ['message' => 'b']], $this->minute);
        $path = $spool->path('logs', $this->minute);

        $this->assertTrue($spool->rewrite($path, [['message' => 'b']]));
        $this->assertSame([['message' => 'b']], $spool->read($path));
        $this->assertTrue($spool->rewrite($path, []));
        $this->assertFileDoesNotExist($path);
    }

    public function testSenderLockIsExclusive(): void
    {
        $first = new Spool($this->dir);
        $first->usable();
        $second = new Spool($this->dir);

        $this->assertTrue($first->acquireSenderLock());
        $this->assertFalse($second->acquireSenderLock());
        $first->releaseSenderLock();
        $this->assertTrue($second->acquireSenderLock());
        $second->releaseSenderLock();
    }

    public function testCleanupDropsOldFilesAndKeepsTheSpoolSmall(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();
        $now = $this->minute + 7200;
        $spool->append('logs', [['message' => 'old']], $this->minute);
        $spool->append('logs', [['message' => 'recent']], $now - 120);

        $this->assertSame(1, $spool->cleanup($now));
        $this->assertFileDoesNotExist($spool->path('logs', $this->minute));
        $this->assertFileExists($spool->path('logs', $now - 120));
    }

    public function testCleanupDeletesTheOldestWhenOverTheSizeLimit(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();
        $now = $this->minute + 600;
        $chunk = str_repeat('x', (int) (3.5 * 1024 * 1024));
        for ($i = 0; $i < 6; $i++) {
            $spool->append('logs', [['message' => $chunk]], $this->minute + $i * 60);
        }

        $this->assertSame(1, $spool->cleanup($now));
        $this->assertFileDoesNotExist($spool->path('logs', $this->minute));
        $this->assertFileExists($spool->path('logs', $this->minute + 60));
    }

    public function testMinuteFileStopsGrowingAtItsLimit(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();
        $chunk = str_repeat('x', 3 * 1024 * 1024);
        $this->assertTrue($spool->append('logs', [['message' => $chunk]], $this->minute));
        $this->assertTrue($spool->append('logs', [['message' => $chunk]], $this->minute));

        $this->assertCount(1, $spool->read($spool->path('logs', $this->minute)));
    }

    public function testDeferredSending(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();

        $this->assertTrue($spool->sendAllowed($this->minute));
        $spool->deferSend(60, $this->minute);
        $this->assertFalse($spool->sendAllowed($this->minute + 30));
        $this->assertTrue($spool->sendAllowed($this->minute + 60));
    }

    public function testState(): void
    {
        $spool = new Spool($this->dir);
        $spool->usable();

        $this->assertNull($spool->readState('dependencies'));
        $this->assertTrue($spool->writeState('dependencies', '{"a":1}'));
        $this->assertSame('{"a":1}', $spool->readState('dependencies'));
    }

    public function testUnwritableDirectory(): void
    {
        $spool = new Spool('/proc/watch-php-cannot-exist/' . bin2hex(random_bytes(4)));

        $this->assertFalse($spool->usable());
        $this->assertFalse($spool->append('logs', [['message' => 'x']]));
    }
}
