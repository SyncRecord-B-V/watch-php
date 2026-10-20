<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests\Fake;

use SyncRecord\Watch\Http\HttpResponse;
use SyncRecord\Watch\Http\HttpSender;

/**
 * Records requests and answers with queued responses (200 when the queue is empty). Never
 * touches the network.
 */
final class FakeSender implements HttpSender
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, timeout: float|null}> */
    public array $requests = [];

    /** @var list<HttpResponse> */
    private array $responses = [];

    public function willRespond(HttpResponse ...$responses): self
    {
        foreach ($responses as $response) {
            $this->responses[] = $response;
        }

        return $this;
    }

    public function send(string $url, array $headers, string $body, ?float $timeout = null): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];

        return array_shift($this->responses) ?? new HttpResponse(200, 'OK');
    }

    /**
     * The JSON body of a request (gunzipped when needed).
     *
     * @return array<mixed>
     */
    public function json(int $index = 0): array
    {
        $body = $this->requests[$index]['body'];
        if (($this->requests[$index]['headers']['Content-Encoding'] ?? null) === 'gzip') {
            $body = (string) gzdecode($body);
        }
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }
}
