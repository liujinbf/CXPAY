<?php

declare(strict_types=1);

namespace tests\Unit;

use app\service\CloudInstanceClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class CloudHeartbeatSyncTest extends TestCase
{
    private string $tempIdentityFile;
    private string $tempRuntimeDir;

    protected function setUp(): void
    {
        $this->tempIdentityFile = sys_get_temp_dir() . '/test_id_' . bin2hex(random_bytes(6)) . '.json';
        $this->tempRuntimeDir = sys_get_temp_dir() . '/test_runtime_' . bin2hex(random_bytes(6));
        @mkdir($this->tempRuntimeDir . '/instance', 0777, true);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempIdentityFile)) {
            @unlink($this->tempIdentityFile);
        }
        $this->recursiveRmdir($this->tempRuntimeDir);
    }

    public function testSendHeartbeatWhenNotActivatedReturnsGracefulOffline(): void
    {
        $client = new CloudInstanceClient($this->tempIdentityFile, 'https://mock.cloud.cxpay.com');
        $res = $client->sendHeartbeat();

        $this->assertSame(-1, $res['code']);
        $this->assertFalse($res['data']['synced']);
        $this->assertStringContainsString('尚未激活', $res['msg']);
    }

    public function testSendHeartbeatSyncsEntitlementsAndDirectives(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'code' => 1,
                'msg'  => 'ok',
                'data' => [
                    'server_time' => 1786100000,
                    'entitlements' => [
                        'cxpay.driver.wechat_dy_bill' => [
                            'plugin_id'  => 'cxpay.driver.wechat_dy_bill',
                            'granted_at' => '2026-09-04 12:00:00',
                            'type'       => 'PERMANENT',
                            'expires_at' => null,
                        ],
                    ],
                    'revocations' => [
                        'cxpay.driver.malicious_test_driver',
                    ],
                    'directives' => [
                        ['id' => 'notice_01', 'title' => '全网系统维护升级通知', 'level' => 'INFO'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $entitlementFile = $this->tempRuntimeDir . '/instance/entitlements.json';
        $directivesFile = $this->tempRuntimeDir . '/instance/cloud_directives.json';
        $client = new CloudInstanceClient(
            $this->tempIdentityFile,
            'https://mock.cloud.cxpay.com',
            $httpClient,
            $entitlementFile,
            $directivesFile
        );

        // 预置已激活实例凭证
        $identity = $client->getIdentity();
        $identity['instance_id'] = 'ins_test_heartbeat_001';
        $identity['domain'] = 'pay.example.com';
        $identity['activated'] = true;
        file_put_contents($this->tempIdentityFile, json_encode($identity));

        $res = $client->sendHeartbeat();

        $this->assertSame(1, $res['code']);
        $this->assertTrue($res['data']['synced']);
        $this->assertSame('ins_test_heartbeat_001', $res['data']['instance_id']);
        $this->assertContains('cxpay.driver.wechat_dy_bill', $res['data']['synced_entitlements']);

        // 校验发送的请求包含 Ed25519 规范串签名头部
        $lastRequest = $mock->getLastRequest();
        $this->assertNotNull($lastRequest);
        $this->assertSame('ins_test_heartbeat_001', $lastRequest->getHeaderLine('X-CXPAY-Instance'));
        $this->assertNotEmpty($lastRequest->getHeaderLine('X-CXPAY-Signature'));
        $this->assertNotEmpty($lastRequest->getHeaderLine('X-CXPAY-Nonce'));
        $this->assertNotEmpty($lastRequest->getHeaderLine('X-CXPAY-Timestamp'));

        // 测试数据只能写入本用例的临时运行时目录，不能污染项目 runtime。
        $this->assertFileExists($entitlementFile);
        $ents = json_decode((string)file_get_contents($entitlementFile), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('cxpay.driver.wechat_dy_bill', $ents);

        $this->assertFileExists($directivesFile);
        $storedDirectives = json_decode(
            (string)file_get_contents($directivesFile),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('notice_01', $storedDirectives['directives'][0]['id']);
    }

    private function recursiveRmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "{$dir}/{$file}";
            is_dir($path) ? $this->recursiveRmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
