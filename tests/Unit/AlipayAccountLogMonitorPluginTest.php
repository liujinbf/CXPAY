<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\ServerPollingDriverInterface;
use app\payment\Plugin\PluginManifest;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use plugin\cxpay\alipay_accountlog_monitor\AccountLogClient;
use plugin\cxpay\alipay_accountlog_monitor\Driver;

require_once __DIR__ . '/../../plugins-src/alipay-accountlog-monitor/src/Driver.php';

final class AlipayAccountLogMonitorPluginTest extends TestCase
{
    /** @var resource|\OpenSSLAsymmetricKey */
    private $privateKey;
    private string $privatePem = '';
    private string $publicPem = '';

    protected function setUp(): void
    {
        $this->privateKey = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertNotFalse($this->privateKey);
        self::assertTrue(openssl_pkey_export($this->privateKey, $this->privatePem));
        $details = openssl_pkey_get_details($this->privateKey);
        self::assertIsArray($details);
        $this->publicPem = $details['key'];
    }

    public function testManifestDeclaresOptionalServerMonitor(): void
    {
        $manifest = PluginManifest::fromJson((string)file_get_contents(
            __DIR__ . '/../../plugins-src/alipay-accountlog-monitor/manifest.json'
        ));
        $driver = new Driver();

        self::assertSame('cxpay.alipay.accountlog_monitor', $manifest->id());
        self::assertSame('alipay_accountlog_monitor', $manifest->drivers()[0]['code']);
        self::assertSame(MonitorableDriverInterface::MODE_SERVER, $driver->monitorMode());
        self::assertInstanceOf(ServerPollingDriverInterface::class, $driver);
        self::assertStringContainsString('免 CK 自动配置', $driver->getMeta()['title']);
        self::assertSame('notice', $driver->getMeta()['inputs'][0]['type']);
        self::assertStringContainsString(
            '自动配置',
            $driver->getMeta()['inputs'][0]['content']
        );
    }

    public function testParsesOnlyIncomeFromSignedOfficialResponse(): void
    {
        $node = json_encode([
            'code' => '10000',
            'msg' => 'Success',
            'detail_list' => [
                [
                    'direction' => '收入',
                    'alipay_order_no' => '2026072922001000000000000001',
                    'trans_amount' => '1,288.88',
                    'trans_dt' => '2026-07-29 12:30:00',
                    'trans_memo' => '收款',
                ],
                [
                    'direction' => '支出',
                    'alipay_order_no' => '2026072922001000000000000002',
                    'trans_amount' => '10.00',
                    'trans_dt' => '2026-07-29 12:30:01',
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = '';
        self::assertTrue(openssl_sign($node, $signature, $this->privateKey, OPENSSL_ALGO_SHA256));
        $body = '{"alipay_data_bill_accountlog_query_response":' . $node
            . ',"sign":"' . base64_encode($signature) . '"}';
        $client = $this->clientWithResponse($body);

        $events = $client->query(
            '2026000000000000',
            $this->privatePem,
            $this->publicPem,
            strtotime('2026-07-29 12:29:00'),
            strtotime('2026-07-29 12:31:00')
        );

        self::assertCount(1, $events);
        self::assertSame('2026072922001000000000000001', $events[0]['source_bill_id']);
        self::assertSame('1288.88', $events[0]['amount']);
        self::assertSame(strtotime('2026-07-29 12:30:00'), $events[0]['occurred_at']);
    }

    public function testRejectsResponseWithInvalidSignature(): void
    {
        $node = '{"code":"10000","detail_list":[]}';
        $body = '{"alipay_data_bill_accountlog_query_response":' . $node
            . ',"sign":"' . base64_encode('invalid') . '"}';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('响应验签失败');
        $this->clientWithResponse($body)->query(
            '2026000000000000',
            $this->privatePem,
            $this->publicPem,
            time() - 120,
            time()
        );
    }

    public function testChannelValidationRequiresRealKeys(): void
    {
        $result = (new Driver())->upchannel([], [
            'qr_url' => 'https://qr.alipay.com/example',
            'app_id' => '2026000000000000',
            'app_private_key' => $this->privatePem,
            'alipay_public_key' => $this->publicPem,
        ]);

        self::assertSame('2026000000000000', $result['app_id']);
        self::assertArrayNotHasKey('code', $result);
    }

    private function clientWithResponse(string $body): AccountLogClient
    {
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $body)]);
        return new AccountLogClient(new Client(['handler' => HandlerStack::create($mock)]));
    }
}
