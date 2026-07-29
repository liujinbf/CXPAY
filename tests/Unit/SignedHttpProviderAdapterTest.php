<?php

declare(strict_types=1);

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WxCollector\SignedHttpProviderAdapter;

final class SignedHttpProviderAdapterTest extends TestCase
{
    public function testVerifiesProviderResponseAndReturnsBills(): void
    {
        $requestSecret = str_repeat('r', 32);
        $responseSecret = str_repeat('p', 32);
        $raw = '{"data":[{"ack_token":"cursor-1","source_bill_id":"bill-1"}]}';
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [
                'Content-Type' => 'application/json',
                'X-Provider-Signature' => hash_hmac('sha256', $raw, $responseSecret),
            ], $raw),
        ]));
        $stack->push(Middleware::history($history));
        $adapter = new SignedHttpProviderAdapter(
            'https://authorized-provider.example.com',
            'provider-client-01',
            $requestSecret,
            $responseSecret,
            new Client(['handler' => $stack, 'base_uri' => 'https://authorized-provider.example.com'])
        );

        $events = $adapter->pullPaymentEvents(50);

        self::assertSame('cursor-1', $events[0]['ack_token']);
        $request = $history[0]['request'];
        $timestamp = $request->getHeaderLine('X-Provider-Timestamp');
        $nonce = $request->getHeaderLine('X-Provider-Nonce');
        $expected = hash_hmac('sha256', implode("\n", [
            'GET',
            '/v1/payment-events?limit=50',
            $timestamp,
            $nonce,
            hash('sha256', ''),
        ]), $requestSecret);
        self::assertSame($expected, $request->getHeaderLine('X-Provider-Signature'));
    }

    public function testRejectsUnsignedProviderResponse(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"data":[]}')]));
        $adapter = new SignedHttpProviderAdapter(
            'https://authorized-provider.example.com',
            'provider-client-01',
            str_repeat('r', 32),
            str_repeat('p', 32),
            new Client(['handler' => $stack, 'base_uri' => 'https://authorized-provider.example.com'])
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('响应签名无效');
        $adapter->pullPaymentEvents(50);
    }
}
