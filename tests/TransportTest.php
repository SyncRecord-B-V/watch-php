<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Config;
use SyncRecord\Watch\Http\HttpResponse;
use SyncRecord\Watch\Result;
use SyncRecord\Watch\Tests\Fake\FakeSender;
use SyncRecord\Watch\Transport;
use SyncRecord\Watch\Watch;

final class TransportTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function statusCodes(): array
    {
        return [
            '200' => [200, Result::OK],
            '202' => [202, Result::OK],
            '204' => [204, Result::OK],
            '400' => [400, Result::DROP],
            '401' => [401, Result::DROP],
            '404' => [404, Result::DROP],
            '408' => [408, Result::RETRY],
            '413' => [413, Result::DROP],
            '422' => [422, Result::DROP],
            '425' => [425, Result::RETRY],
            '429' => [429, Result::RETRY],
            '500' => [500, Result::RETRY],
            '501' => [501, Result::DROP],
            '502' => [502, Result::RETRY],
            '503' => [503, Result::RETRY],
            '504' => [504, Result::RETRY],
        ];
    }

    #[DataProvider('statusCodes')]
    public function testRetryDecision(int $status, string $expected): void
    {
        $this->assertSame($expected, Transport::classify(new HttpResponse($status, '{"error":"x"}'))->status);
    }

    public function testNetworkErrorsAreRetried(): void
    {
        $result = Transport::classify(new HttpResponse(0, '', 'curl error 7: Failed to connect'));

        $this->assertTrue($result->shouldRetry());
        $this->assertFalse($result->timedOut);
        $this->assertTrue(Transport::classify(new HttpResponse(0, '', 'curl error 28: timed out', true))->timedOut);
        $this->assertTrue(Transport::classify(new HttpResponse(0))->shouldRetry());
    }

    public function testPostsGzipJsonWithTheKey(): void
    {
        $sender = new FakeSender();
        $config = Config::fromArray(['url' => 'https://watch.example.com/', 'api_key' => 'lh_example'], []);
        $result = (new Transport($config, $sender))->post(Transport::LOGS, ['entries' => [['message' => 'hi', 'path' => '/a/b']]]);

        $this->assertTrue($result->isOk());
        $request = $sender->requests[0];
        $this->assertSame('https://watch.example.com/api/v1/logs', $request['url']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame('Bearer lh_example', $request['headers']['Authorization']);
        $this->assertSame('watch-php/' . Watch::VERSION . ' php/' . PHP_VERSION, $request['headers']['User-Agent']);
        if (function_exists('gzencode')) {
            $this->assertSame('gzip', $request['headers']['Content-Encoding']);
        }
        $this->assertSame(['entries' => [['message' => 'hi', 'path' => '/a/b']]], $sender->json());
    }

    public function testRefusesPlainHttpToARemoteHost(): void
    {
        $sender = new FakeSender();
        $config = Config::fromArray(['url' => 'http://watch.example.com', 'api_key' => 'lh_example'], []);
        $result = (new Transport($config, $sender))->post(Transport::ERRORS, ['errors' => []]);

        $this->assertTrue($result->isDropped());
        $this->assertSame([], $sender->requests);
    }

    public function testPlainHttpToLocalhostIsFine(): void
    {
        $sender = new FakeSender();
        $config = Config::fromArray(['url' => 'http://localhost:3000', 'api_key' => 'lh_example'], []);

        $this->assertTrue((new Transport($config, $sender))->post(Transport::METRICS, ['requests' => []])->isOk());
        $this->assertSame('http://localhost:3000/api/v1/metrics', $sender->requests[0]['url']);
    }

    public function testDropKeepsTheStartOfTheAnswer(): void
    {
        $sender = (new FakeSender())->willRespond(new HttpResponse(401, '{"error":"invalid or missing API key"}'));
        $config = Config::fromArray(['url' => 'https://watch.example.com', 'api_key' => 'lh_wrong'], []);
        $result = (new Transport($config, $sender))->post(Transport::LOGS, ['entries' => []]);

        $this->assertTrue($result->isDropped());
        $this->assertSame(401, $result->code);
        $this->assertStringContainsString('invalid or missing API key', (string) $result->error);
    }
}
