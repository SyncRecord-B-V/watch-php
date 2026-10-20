<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\CheckIn;
use SyncRecord\Watch\Config;
use SyncRecord\Watch\Http\HttpResponse;
use SyncRecord\Watch\Tests\Fake\FakeSender;

final class CheckInTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $this->config = Config::fromArray(['url' => 'https://watch.example.com/', 'environment' => 'production'], []);
    }

    public function testUrlFromToken(): void
    {
        $this->assertSame('https://watch.example.com/ping/tok_abc123', CheckIn::url($this->config, 'tok_abc123'));
        $this->assertSame('https://watch.example.com/ping/tok_abc123/fail', CheckIn::url($this->config, 'tok_abc123', true));
        $this->assertSame(
            'https://watch.example.com/ping/tok_abc123?msg=exported%2012%20rows',
            CheckIn::url($this->config, 'tok_abc123', false, 'exported 12 rows'),
        );
        $this->assertSame('https://watch.example.com/ping/a%2Fb', CheckIn::url($this->config, 'a/b'));
    }

    public function testFullUrlIsUsedAsIs(): void
    {
        $this->assertSame('https://other.example/ping/tok', CheckIn::url($this->config, 'https://other.example/ping/tok'));
        $this->assertSame('https://other.example/ping/tok/fail', CheckIn::url($this->config, 'https://other.example/ping/tok/success', true));
        $this->assertSame('https://other.example/ping/tok', CheckIn::url($this->config, 'https://other.example/ping/tok/fail'));
        $this->assertSame(
            'https://other.example/ping/tok?a=1&msg=hi',
            CheckIn::url($this->config, 'https://other.example/ping/tok?a=1', false, 'hi'),
        );
    }

    public function testMessageIsCut(): void
    {
        $url = (string) CheckIn::url($this->config, 'tok', false, str_repeat('x', 400));

        $this->assertStringEndsWith('?msg=' . str_repeat('x', 250), $url);
    }

    public function testNeedsAUrlForATokenOrNothingAtAll(): void
    {
        $this->assertNull(CheckIn::url(Config::fromArray([], []), 'tok'));
        $this->assertNull(CheckIn::url($this->config, '  '));
    }

    public function testPingPostsToTheUrl(): void
    {
        $sender = new FakeSender();

        $this->assertTrue(CheckIn::ping($this->config, $sender, 'tok_abc123', false, 'done'));
        $this->assertSame('https://watch.example.com/ping/tok_abc123?msg=done', $sender->requests[0]['url']);
        $this->assertSame('text/plain; charset=utf-8', $sender->requests[0]['headers']['Content-Type']);
        $this->assertSame('done', $sender->requests[0]['body']);
    }

    public function testPingReturnsFalseOnRefusal(): void
    {
        $sender = (new FakeSender())->willRespond(new HttpResponse(404, "Not found\n"));

        $this->assertFalse(CheckIn::ping($this->config, $sender, 'unknown_token'));
    }

    public function testPingIsOffInTheTestEnvironment(): void
    {
        $sender = new FakeSender();
        $config = Config::fromArray(['url' => 'https://watch.example.com', 'environment' => 'test'], []);

        $this->assertFalse(CheckIn::ping($config, $sender, 'tok'));
        $this->assertSame([], $sender->requests);
    }

    public function testPingRefusesPlainHttpToARemoteHost(): void
    {
        $sender = new FakeSender();
        $config = Config::fromArray(['url' => 'http://watch.example.com', 'environment' => 'production'], []);

        $this->assertFalse(CheckIn::ping($config, $sender, 'tok'));
        $this->assertSame([], $sender->requests);
    }
}
