<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\WxpayClerkDatabaseTestCase;
use RuntimeException;
use WxpayClerk\ApiException;
use WxpayClerk\Database;
use WxpayClerk\HttpResponse;
use WxpayClerk\NonceRepository;
use WxpayClerk\RequestAuthenticator;
use WxpayClerk\SignatureHelper;

require_once __DIR__ . '/../Support/WxpayClerkDatabaseTestCase.php';

final class WxpayClerkRequestAuthenticatorTest extends WxpayClerkDatabaseTestCase
{
    private const CLIENT_ID = 'client_1';
    private const CLIENT_SECRET = 'ssssssssssssssssssssssssssssssss';

    private Database $database;
    private RequestAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Database($this->databasePath);
        $this->authenticator = new RequestAuthenticator(
            self::CLIENT_ID,
            self::CLIENT_SECRET,
            new NonceRepository($this->database->pdo())
        );
    }

    protected function tearDown(): void
    {
        unset($this->authenticator, $this->database);
        parent::tearDown();
    }

    public function testAuthenticatesKnownSignedRequestOnceAndRejectsReplay(): void
    {
        $headers = [
            'x-cxpay-client' => self::CLIENT_ID,
            'x-cxpay-timestamp' => '1700000000',
            'x-cxpay-nonce' => '0123456789abcdef',
            'x-cxpay-signature' => '7ce9470c2a592f0c525d2d80e96f6f3ad3bd0da1fe03f41e915efa34f86c72dd',
        ];

        self::assertSame(
            self::CLIENT_ID,
            $this->authenticator->authenticate('POST', '/v1/orders', $headers, '{}', 1700000000)
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(409);
        $this->authenticator->authenticate('POST', '/v1/orders', $headers, '{}', 1700000000);
    }

    public function testInvalidSignatureDoesNotConsumeNonce(): void
    {
        $valid = $this->signedHeaders('GET', '/v1/ops/status', '', 1700000000, 'nonce-not-consumed');
        $invalid = array_merge($valid, ['x-cxpay-signature' => str_repeat('0', 64)]);

        try {
            $this->authenticator->authenticate('GET', '/v1/ops/status', $invalid, '', 1700000000);
            self::fail('错误签名必须被拒绝');
        } catch (ApiException $exception) {
            self::assertSame(401, $exception->status);
        }

        self::assertSame(
            self::CLIENT_ID,
            $this->authenticator->authenticate('GET', '/v1/ops/status', $valid, '', 1700000000)
        );
    }

    public function testRejectsTimestampOutsideFiveMinuteWindow(): void
    {
        $headers = $this->signedHeaders('GET', '/v1/ops/status', '', 1700000000, 'nonce-clock-window');

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(401);
        $this->authenticator->authenticate('GET', '/v1/ops/status', $headers, '', 1700000301);
    }

    public function testRejectsShortNonceBeforePersistence(): void
    {
        $headers = $this->signedHeaders('GET', '/v1/ops/status', '', 1700000000, 'too-short');

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(401);
        $this->authenticator->authenticate('GET', '/v1/ops/status', $headers, '', 1700000000);
    }

    public function testExpiredNonceCanBeClaimedAgain(): void
    {
        $nonce = 'nonce-reusable-01';
        $first = $this->signedHeaders('GET', '/v1/ops/status', '', 1700000000, $nonce);
        $second = $this->signedHeaders('GET', '/v1/ops/status', '', 1700000301, $nonce);

        self::assertSame(
            self::CLIENT_ID,
            $this->authenticator->authenticate('GET', '/v1/ops/status', $first, '', 1700000000)
        );
        self::assertSame(
            self::CLIENT_ID,
            $this->authenticator->authenticate('GET', '/v1/ops/status', $second, '', 1700000301)
        );
    }

    public function testHttpResponsePreservesRawJsonAndAddsHeaderImmutably(): void
    {
        $response = HttpResponse::json(['message' => '店员在线'], 202);
        $signed = $response->withHeader('X-CXPAY-Signature', 'signature-value');

        self::assertSame(202, $response->status);
        self::assertSame('{"message":"店员在线"}', $response->body);
        self::assertArrayNotHasKey('X-CXPAY-Signature', $response->headers);
        self::assertSame('signature-value', $signed->headers['X-CXPAY-Signature']);
        self::assertSame('application/json; charset=utf-8', $signed->headers['Content-Type']);
    }

    public function testResponseSignatureMatchesProtocolVector(): void
    {
        $signer = new SignatureHelper(self::CLIENT_ID, self::CLIENT_SECRET, str_repeat('c', 32));

        self::assertSame(
            '15ce59d3d5189a26a217e1e09be6b5c51a65e1b705cbce0dc1a51e200b8b9a80',
            $signer->signResponse('{"ok":true}')
        );
    }

    public function testRejectsWeakClientSecretAtConstruction(): void
    {
        $this->expectException(RuntimeException::class);
        new RequestAuthenticator(
            self::CLIENT_ID,
            'short-secret',
            new NonceRepository($this->database->pdo())
        );
    }

    /** @return array<string, string> */
    private function signedHeaders(string $method, string $path, string $body, int $timestamp, string $nonce): array
    {
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
        return [
            'x-cxpay-client' => self::CLIENT_ID,
            'x-cxpay-timestamp' => (string) $timestamp,
            'x-cxpay-nonce' => $nonce,
            'x-cxpay-signature' => hash_hmac('sha256', $canonical, self::CLIENT_SECRET),
        ];
    }
}
