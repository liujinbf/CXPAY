<?php

declare(strict_types=1);

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use WxCollector\AlipayWebProviderAdapter;
use WxCollector\EncryptedFileStateStore;

final class AlipayWebProviderAdapterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cxpay-ali-adapter-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        is_dir($this->directory) && rmdir($this->directory);
    }

    public function testCreatesQrAndEncryptsSessionState(): void
    {
        $adapter = $this->adapter([
            new Response(200, ['Set-Cookie' => ['seed=secret-cookie; Path=/']], $this->loginHtml()),
        ]);

        $result = $adapter->startAuthorization($this->task('CLAIMED'));

        self::assertSame('QR_READY', $result['status']);
        self::assertStringStartsWith('https://qr.alipay.com/', $result['qr_url']);
        $ciphertext = (string)file_get_contents(glob($this->directory . '/*.state')[0]);
        self::assertStringNotContainsString('secret-cookie', $ciphertext);
        self::assertStringNotContainsString('security-token-001', $ciphertext);
    }

    public function testWaitingPollDoesNotInventAStateChange(): void
    {
        $adapter = $this->adapter([
            new Response(200, [], $this->loginHtml()),
            new Response(200, [], 'light.request._callbacks.callback2({"status":"waiting"})'),
        ]);
        $adapter->startAuthorization($this->task('CLAIMED'));

        self::assertNull($adapter->pollAuthorization($this->task('QR_READY')));
    }

    public function testConfirmedLoginReturnsOpaqueAccountReferenceWithoutCookie(): void
    {
        $adapter = $this->adapter([
            new Response(200, [], $this->loginHtml()),
            new Response(200, [], 'light.request._callbacks.callback2({"status":"confirmed"})'),
            new Response(302, ['Set-Cookie' => [
                'ALIPAYJSESSIONID=session-secret; Path=/; Secure',
                'CLUB_ALIPAY_COM=user-secret; Path=/; Secure',
            ]], ''),
        ]);
        $adapter->startAuthorization($this->task('CLAIMED'));

        $result = $adapter->pollAuthorization($this->task('QR_READY'));

        self::assertSame('CONFIRMED', $result['status']);
        self::assertStringStartsWith('ali_', $result['external_ref']);
        self::assertArrayNotHasKey('cookies', $result);
        self::assertArrayNotHasKey('cookie', $result);
        self::assertSame([], $adapter->pullPaymentEvents(50));
    }

    public function testProviderErrorIsNotTreatedAsScanConfirmation(): void
    {
        $adapter = $this->adapter([
            new Response(200, [], $this->loginHtml()),
            new Response(200, [], '{"errormsg":"RefererCheckFailed","success":"false"}'),
        ]);
        $adapter->startAuthorization($this->task('CLAIMED'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('扫码状态接口暂时不可用');
        $adapter->pollAuthorization($this->task('QR_READY'));
    }

    public function testMovesConfirmedSessionToCloudAccountIdempotently(): void
    {
        $store = new EncryptedFileStateStore($this->directory, base64_encode(str_repeat('k', 32)));
        $store->put('was_1234567890123456', [
            'cookies' => ['ALIPAYJSESSIONID' => 'secret-session'],
            'confirmed_at' => time(),
        ]);
        $adapter = new AlipayWebProviderAdapter($store, new Client([
            'handler' => HandlerStack::create(new MockHandler()),
        ]));

        $adapter->bindAuthorizedAccount('was_1234567890123456', 'wxa_1234567890123456');
        $adapter->bindAuthorizedAccount('was_1234567890123456', 'wxa_1234567890123456');

        self::assertNull($store->get('was_1234567890123456'));
        $account = $store->get('wxa_1234567890123456');
        self::assertSame('wxa_1234567890123456', $account['cloud_account_id']);
        self::assertSame('was_1234567890123456', $account['authorization_session_id']);
    }

    /** @param list<Response> $responses */
    private function adapter(array $responses): AlipayWebProviderAdapter
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $client = new Client(['handler' => $handler, 'http_errors' => false]);
        $store = new EncryptedFileStateStore($this->directory, base64_encode(str_repeat('k', 32)));
        return new AlipayWebProviderAdapter($store, $client);
    }

    /** @return array<string, mixed> */
    private function task(string $status): array
    {
        return ['id' => 'was_1234567890123456', 'status' => $status, 'expires_at' => time() + 300];
    }

    private function loginHtml(): string
    {
        return '<script>securityId: "security-token-001",; s.sid = "password-token-001"</script>'
            . '<input type="hidden" value="rds-token-001" name="rds_form_token"/>'
            . '<input type="hidden" id="alieditUid" name="alieditUid" value="uid-token-001" />';
    }
}
