<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Adapter;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SyncRecord\Watch\Client;
use SyncRecord\Watch\Json;
use SyncRecord\Watch\Request;
use SyncRecord\Watch\Watch;

/**
 * PSR-15 middleware (Slim, Mezzio, any PSR-15 stack): names the request from the routing
 * attributes when the router already ran, records its status and time, and reports an
 * exception that passes through before throwing it again. In long-running workers (RoadRunner,
 * FrankenPHP worker mode, Swoole) it also starts and ends Watch's request.
 *
 * Needs psr/http-server-middleware. Watch::start() must have run first.
 */
final class Psr15Middleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $client = Watch::client();
        if ($client === null) {
            return $handler->handle($request);
        }
        $this->begin($client, $request);
        try {
            try {
                $response = $handler->handle($request);
            } catch (\Throwable $error) {
                $this->fail($client, $error);

                throw $error;
            }
            try {
                $client->endRequest($response->getStatusCode());
            } catch (\Throwable) {
                // Never break the response.
            }

            return $response;
        } finally {
            // Done (also after an exception): a long-running server must not keep this request's state.
            try {
                $client->releaseContext();
            } catch (\Throwable) {
                // Never break the response.
            }
        }
    }

    private function begin(Client $client, ServerRequestInterface $request): void
    {
        try {
            $client->beginRequest(self::requestData($request));
            if ($client->scope()->route === null) {
                $route = self::route($request);
                if ($route !== null) {
                    $client->setRoute($route);
                }
            }
        } catch (\Throwable) {
            // Never break the request.
        }
    }

    private function fail(Client $client, \Throwable $error): void
    {
        try {
            $client->notify($error, [], false);
            $status = 500;
            if (method_exists($error, 'getStatusCode')) {
                $status = (int) $error->getStatusCode();
            } elseif (is_a($error, 'Slim\\Exception\\HttpException')) {
                $status = (int) $error->getCode();
            }
            $client->endRequest($status >= 100 && $status < 600 ? $status : 500);
        } catch (\Throwable) {
            // Never hide the app's exception.
        }
    }

    /**
     * The route from attributes the common routers set: Symfony "_controller" / "_route", Slim
     * "__route__", Mezzio RouteResult.
     */
    public static function route(ServerRequestInterface $request): ?string
    {
        $controller = $request->getAttribute('_controller');
        if (is_string($controller) && $controller !== '') {
            return $controller;
        }
        if (is_array($controller) && count($controller) === 2 && is_string($controller[1] ?? null)) {
            $class = is_object($controller[0]) ? get_class($controller[0]) : (string) $controller[0];

            return $class . '::' . $controller[1];
        }
        $slim = $request->getAttribute('__route__');
        if (is_object($slim) && method_exists($slim, 'getPattern')) {
            return $request->getMethod() . ' ' . (string) $slim->getPattern();
        }
        $result = $request->getAttribute('Mezzio\\Router\\RouteResult');
        if (is_object($result) && method_exists($result, 'getMatchedRouteName')) {
            $name = $result->getMatchedRouteName();
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        $name = $request->getAttribute('_route');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Server variables as PHP would set them for this request (for workers without $_SERVER).
     */
    private static function requestData(ServerRequestInterface $request): Request
    {
        $server = $request->getServerParams();
        $uri = $request->getUri();
        $query = $uri->getQuery();
        $server['REQUEST_METHOD'] = $request->getMethod();
        $server['REQUEST_URI'] = $uri->getPath() . ($query === '' ? '' : '?' . $query);
        $server['QUERY_STRING'] = $query;
        if ($uri->getHost() !== '') {
            $server['HTTP_HOST'] = $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
        }
        if ($uri->getScheme() === 'https') {
            $server['HTTPS'] = 'on';
        }
        foreach ($request->getHeaders() as $name => $values) {
            if (strlen((string) $name) > Json::MAX_KEY_BYTES) {
                continue;
            }
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', (string) $name));
            if (!isset($server[$key])) {
                $server[$key] = implode(', ', $values);
            }
        }
        $body = $request->getParsedBody();

        return new Request(
            $server,
            $request->getQueryParams(),
            is_array($body) ? $body : [],
            $request->getCookieParams(),
            true,
        );
    }
}
