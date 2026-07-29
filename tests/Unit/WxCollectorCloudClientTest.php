<?php

declare(strict_types=1);

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WxCollector\CloudClient;

final class WxCollectorCloudClientTest extends TestCase
{
    public function testSignsCollectorRequestUsingRawBody(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(201, ['Content-Type' => 'application/json'], '{"accepted":true,"status":"MATCHED"}'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack, 'base_uri' => 'https://monitor.example.com']);
        $secret = str_repeat('s', 32);
        $client = new CloudClient(
            'https://monitor.example.com',
            'collector-01',
            $secret,
            false,
            $http
        );
        $event = [
            'account_id' => 'wxa_1234567890123456',
            'source_bill_id' => 'WX-BILL-10001',
            'amount' => '10.01',
            'occurred_at' => 1760000000,
        ];

        $result = $client->submitPaymentEvent($event);

        self::assertTrue($result['accepted']);
        $request = $history[0]['request'];
        $body = (string)$request->getBody();
        $timestamp = $request->getHeaderLine('X-Collector-Timestamp');
        $nonce = $request->getHeaderLine('X-Collector-Nonce');
        $expected = hash_hmac('sha256', implode("\n", [
            'POST',
            '/v1/collector/events',
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]), $secret);
        self::assertSame('collector-01', $request->getHeaderLine('X-Collector-Id'));
        self::assertSame($expected, $request->getHeaderLine('X-Collector-Signature'));
        self::assertSame($event, json_decode($body, true, 16, JSON_THROW_ON_ERROR));
    }

    public function testRejectsPlainHttpByDefault(): void
    {
        $this->expectException(\RuntimeException::class);
        new CloudClient('http://monitor.example.com', 'collector-01', str_repeat('s', 32));
    }
}
