<?php

declare(strict_types=1);

namespace SyncRecord\Watch\Tests;

use PHPUnit\Framework\TestCase;
use SyncRecord\Watch\Config;
use SyncRecord\Watch\Filter;

final class FilterTest extends TestCase
{
    public function testMasksKeysThatContainARule(): void
    {
        $filter = new Filter();
        $out = $filter->apply([
            'password' => 'hunter2',
            'password_confirmation' => 'hunter2',
            'Email' => 'a@example.com',
            'api_key' => 'k',
            'access_token' => 't',
            'iban' => 'NL00BANK0123456789',
            'name' => 'kept',
            'count' => 3,
        ]);

        $this->assertSame([
            'password' => '[FILTERED]',
            'password_confirmation' => '[FILTERED]',
            'Email' => '[FILTERED]',
            'api_key' => '[FILTERED]',
            'access_token' => '[FILTERED]',
            'iban' => '[FILTERED]',
            'name' => 'kept',
            'count' => 3,
        ], $out);
    }

    public function testNestedArraysAndLists(): void
    {
        $filter = new Filter();
        $out = $filter->apply([
            'user' => ['id' => 7, 'email' => 'a@example.com', 'profile' => ['secret_answer' => 'x', 'city' => 'Utrecht']],
            'tokens' => ['a', 'b'],
            'items' => [['sku' => 'A1', 'otp' => '123456'], ['sku' => 'B2']],
        ]);

        $this->assertSame(7, $out['user']['id']);
        $this->assertSame('[FILTERED]', $out['user']['email']);
        $this->assertSame('[FILTERED]', $out['user']['profile']['secret_answer']);
        $this->assertSame('Utrecht', $out['user']['profile']['city']);
        $this->assertSame('[FILTERED]', $out['tokens']);
        $this->assertSame('A1', $out['items'][0]['sku']);
        $this->assertSame('[FILTERED]', $out['items'][0]['otp']);
        $this->assertSame(['sku' => 'B2'], $out['items'][1]);
    }

    public function testAppRulesAndDottedRules(): void
    {
        $filter = new Filter(array_merge(Config::DEFAULT_FILTER_KEYS, ['coupon', 'account.pin']));
        $out = $filter->apply(['coupon_ref' => 'X1', 'account' => ['pin' => '1234'], 'pin' => 'ok']);

        $this->assertSame('[FILTERED]', $out['coupon_ref']);
        $this->assertSame('[FILTERED]', $out['account']['pin']);
        $this->assertSame('ok', $out['pin']);
    }

    public function testVeryDeepDataIsMasked(): void
    {
        $data = 'leaf';
        for ($i = 0; $i < 40; $i++) {
            $data = ['level' => $data];
        }
        $out = (new Filter())->apply($data);

        $this->assertStringNotContainsString('leaf', (string) json_encode($out));
    }

    public function testUrlMasksSecretAndPersonalQueryValues(): void
    {
        $filter = new Filter();

        $this->assertSame(
            'https://app.example/orders?token=[FILTERED]&page=2',
            $filter->url('https://app.example/orders?token=abc&page=2#frag'),
        );
        $this->assertSame(
            'https://other.example/reset?token=[FILTERED]&email=[FILTERED]&page=2',
            $filter->url('https://other.example/reset?token=t&email=a%40b.nl&page=2'),
        );
        $this->assertSame('https://a.example/p?q=1&name=[FILTERED]', $filter->url('https://a.example/p?q=1&name=Jan'));
        $this->assertSame('https://a.example/p', $filter->url('https://a.example/p#secret'));
        $this->assertNull($filter->url(null));
    }

    public function testUrlUsesTheAppRulesToo(): void
    {
        $filter = new Filter(array_merge(Config::DEFAULT_FILTER_KEYS, ['coupon']));

        $this->assertSame('https://a.example/?coupon_ref=[FILTERED]&q=1', $filter->url('https://a.example/?coupon_ref=X1&q=1'));
    }

    public function testUrlNestedParams(): void
    {
        $filter = new Filter(array_merge(Config::DEFAULT_FILTER_KEYS, ['account.pin']));

        $this->assertSame(
            'https://a.example/?account[pin]=[FILTERED]&pin=ok',
            $filter->url('https://a.example/?account[pin]=1234&pin=ok'),
        );
        $this->assertSame(
            'https://a.example/?ids[]=1&ids[]=2&tag=a&tag=b&account[pin]=[FILTERED]',
            $filter->url('https://a.example/?ids[]=1&ids[]=2&tag=a&tag=b&account[pin]=9'),
        );
    }

    public function testAmbiguousNamesMaskEverything(): void
    {
        $filter = new Filter();

        $this->assertSame(
            'https://a.example/?a=[FILTERED]&a[b]=[FILTERED]&page=[FILTERED]',
            $filter->url('https://a.example/?a=1&a[b]=2&page=3'),
        );
        $this->assertSame(
            'https://a.example/?list[][id]=[FILTERED]&page=[FILTERED]',
            $filter->url('https://a.example/?list[][id]=1&page=3'),
        );
    }

    public function testDeepLongOrCrowdedQueriesAreLeftOut(): void
    {
        $filter = new Filter();
        $deep = 'https://a.example/p?x' . str_repeat('[a]', 9) . '=1';
        $crowded = 'https://a.example/p?' . implode('&', array_map(static fn (int $n): string => 'p' . $n . '=1', range(1, 150)));

        $this->assertSame('https://a.example/p', $filter->url($deep));
        $this->assertSame('https://a.example/p', $filter->url('https://a.example/p?q=' . str_repeat('a', 5000)));
        $this->assertSame('https://a.example/p', $filter->url($crowded));
    }

    public function testQueryStringAlone(): void
    {
        $filter = new Filter();

        $this->assertSame('token=[FILTERED]&page=2', $filter->queryString('token=abc&page=2'));
        $this->assertSame('flag&page=2', $filter->queryString('flag&page=2'));
        $this->assertSame('', $filter->queryString(''));
    }
}
