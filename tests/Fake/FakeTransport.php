<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests\Fake;

use SyncRecord\Watch\Result;
use SyncRecord\Watch\TransportInterface;

/**
 * Collects payloads instead of sending them; answers with queued results (ok when empty).
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{path: string, payload: array<string, mixed>}> */
    public array $posts = [];

    /** @var list<Result> */
    private array $results = [];

    public function willReturn(Result ...$results): self
    {
        foreach ($results as $result) {
            $this->results[] = $result;
        }

        return $this;
    }

    public function post(string $path, array $payload): Result
    {
        $this->posts[] = ['path' => $path, 'payload' => $payload];

        return array_shift($this->results) ?? Result::ok(202);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloads(string $path): array
    {
        $out = [];
        foreach ($this->posts as $post) {
            if ($post['path'] === $path) {
                $out[] = $post['payload'];
            }
        }

        return $out;
    }
}
