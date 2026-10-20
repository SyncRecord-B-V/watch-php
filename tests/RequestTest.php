<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Request;

final class RequestTest extends TestCase
{
    public function testNormalizesIdsInPaths(): void
    {
        $this->assertSame('/invoices/{id}', Request::normalizePath('/invoices/42'));
        $this->assertSame('/invoices/{id}/lines/{id}', Request::normalizePath('/invoices/42/lines/7'));
        $this->assertSame('/orders/{id}', Request::normalizePath('/orders/3f2504e0-4f89-11d3-9a0c-0305e82c3301'));
        $this->assertSame('/files/{id}', Request::normalizePath('/files/9f86d081884c7d659a2feaa0c55ad015'));
        $this->assertSame('/commits/{id}', Request::normalizePath('/commits/a1b2c3d4'));
        $this->assertSame('/invoices/{id}/', Request::normalizePath('/invoices/42/'));
    }

    public function testKeepsWordsAndSlugs(): void
    {
        $this->assertSame('/', Request::normalizePath(''));
        $this->assertSame('/', Request::normalizePath('/'));
        $this->assertSame('/invoices', Request::normalizePath('/invoices?page=2'));
        $this->assertSame('/blog/cafe', Request::normalizePath('/blog/cafe'));
        $this->assertSame('/blog/deadbeef', Request::normalizePath('/blog/deadbeef'));
        $this->assertSame('/blog/my-first-post', Request::normalizePath('/blog/my-first-post'));
        $this->assertSame('/v2/users', Request::normalizePath('/v2/users'));
        $this->assertSame('/a/{id}', Request::normalizePath('a/12'));
    }

    public function testEndpoint(): void
    {
        $this->assertSame('GET /invoices/{id}', Request::endpoint('GET', '/invoices/42'));
    }

    public function testRouteNames(): void
    {
        $this->assertSame('InvoiceController#show', Request::routeName('App\\Http\\Controllers\\InvoiceController@show', 'GET'));
        $this->assertSame('Admin\\InvoiceController#show', Request::routeName('App\\Http\\Controllers\\Admin\\InvoiceController@show', 'GET'));
        $this->assertSame('InvoiceController#show', Request::routeName('App\\Controller\\InvoiceController::show', 'GET'));
        $this->assertSame('Shop\\Web\\Invoices#index', Request::routeName('Shop\\Web\\Invoices::index', 'GET'));
        $this->assertSame('ShowInvoice#__invoke', Request::routeName('App\\Http\\Controllers\\ShowInvoice', 'GET'));
        $this->assertSame('InvoiceController#show', Request::routeName('InvoiceController#show', 'GET'));
        $this->assertSame('POST /invoices/{id}', Request::routeName('POST /invoices/{id}', 'GET'));
        $this->assertSame('GET invoice_show', Request::routeName('invoice_show', 'GET'));
        $this->assertSame('invoice_show', Request::routeName('invoice_show'));
        $this->assertNull(Request::routeName('  '));
        $this->assertNull(Request::routeName(null));
    }

    public function testRequestData(): void
    {
        $request = new Request([
            'REQUEST_METHOD' => 'post',
            'REQUEST_URI' => '/invoices/42?x=1#frag',
            'HTTP_HOST' => 'shop.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_REQUEST_ID' => 'abc-123',
            'REQUEST_TIME_FLOAT' => 1800000000.25,
        ], [], [], [], true);

        $this->assertTrue($request->isHttp());
        $this->assertSame('POST', $request->method());
        $this->assertSame('/invoices/42', $request->path());
        $this->assertSame('/invoices/42?x=1', $request->pathWithQuery());
        $this->assertSame('https://shop.example.com/invoices/42?x=1', $request->url());
        $this->assertSame('abc-123', $request->requestId());
        $this->assertSame(1800000000.25, $request->startTime());
    }

    public function testGeneratedRequestIdIsStable(): void
    {
        $request = new Request(['HTTP_X_REQUEST_ID' => "bad id\n"], [], [], [], true);
        $id = $request->requestId();

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $id);
        $this->assertSame($id, $request->requestId());
    }

    public function testUrlWithoutHostHeader(): void
    {
        $request = new Request(['REQUEST_URI' => '/a', 'SERVER_NAME' => 'example.com', 'SERVER_PORT' => '8080'], [], [], [], true);

        $this->assertSame('http://example.com:8080/a', $request->url());
    }

    public function testCliIsNotHttp(): void
    {
        $this->assertFalse(Request::fromGlobals()->isHttp());
    }

    public function testCliComponent(): void
    {
        $this->assertSame('artisan#queue:work', Request::cliComponent(['argv' => ['artisan', 'queue:work', '--once']]));
        $this->assertSame('cron.php#run', Request::cliComponent(['argv' => ['/var/www/app/bin/cron.php', '--verbose']]));
        $this->assertNull(Request::cliComponent([]));
    }
}
