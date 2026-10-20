<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Adapter;

use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware that records every call (host, time, status, API quota headers, timeouts):
 *
 *   $stack = GuzzleHttp\HandlerStack::create();
 *   $stack->push(SyncRecord\Watch\Adapter\GuzzleMiddleware::create(), 'watch');
 *   $client = new GuzzleHttp\Client(['handler' => $stack]);
 */
final class GuzzleMiddleware
{
    public static function create(): callable
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                $start = hrtime(true);

                return $handler($request, $options)->then(
                    static function ($response) use ($request, $start) {
                        HttpRecorder::record($request, $response instanceof ResponseInterface ? $response : null, $start);

                        return $response;
                    },
                    static function ($reason) use ($request, $start) {
                        $response = is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
                        if ($response instanceof ResponseInterface) {
                            HttpRecorder::record($request, $response, $start);
                        } else {
                            HttpRecorder::record($request, null, $start, $reason);
                        }

                        return Create::rejectionFor($reason);
                    },
                );
            };
        };
    }
}
