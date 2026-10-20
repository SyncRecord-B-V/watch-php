<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Adapter;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Wraps any PSR-18 HTTP client and records every call (host, time, status, API quota headers,
 * timeouts). Needs psr/http-client.
 *
 *   $client = new Psr18Client($yourPsr18Client);
 */
final class Psr18Client implements ClientInterface
{
    public function __construct(private readonly ClientInterface $inner)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $start = hrtime(true);
        try {
            $response = $this->inner->sendRequest($request);
        } catch (\Throwable $failure) {
            HttpRecorder::record($request, null, $start, $failure);

            throw $failure;
        }
        HttpRecorder::record($request, $response, $start);

        return $response;
    }
}
