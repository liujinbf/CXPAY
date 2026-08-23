<?php

declare(strict_types=1);

namespace tests\Unit;

use app\service\CloudInstanceClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class CloudInstanceClientTest extends TestCase
{
    private string $tempIdentityFile;

    protected function setUp(): void
    {
        $this->tempIdentityFile = sys_get_temp_dir() . '/test_instance_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempIdentityFile)) {
            @unlink($this->tempIdentityFile);
        }
    }

    public function testGeneratesIdentityAndFingerprint(): void
    {
        $client = new CloudInstanceClient($this->tempIdentityFile, 'https://mock.cloud.cxpay.com');
        $identity = $client->getIdentity();

        $this->assertNotEmpty($identity['public_key']);
        $this->assertNotEmpty($identity['secret_key']);
        $this->assertSame(64, strlen($identity['fingerprint']));
        $this->assertFalse($identity['activated']);

        // 二次获取复用同一身份
        $second = $client->getIdentity();
        $this->assertSame($identity['public_key'], $second['public_key']);
        $this->assertSame($identity['fingerprint'], $second['fingerprint']);
    }

    public function testCanonicalStringFormat(): void
    {
        $canonical = CloudInstanceClient::buildCanonicalString(
            httpMethod: 'get',
            requestPath: '/api/instance/v1/plugins/catalog?v=1&a=2',
            timestamp: 1786000000,
            nonce: 'test_nonce_12345678',
            rawBody: '',
            instanceId: 'ins_01test'
        );

        $lines = explode("\n", $canonical);
        $this->assertCount(6, $lines);
        $this->assertSame('GET', $lines[0]);
        $this->assertSame('/api/instance/v1/plugins/catalog?a=2&v=1', $lines[1]);
        $this->assertSame('1786000000', $lines[2]);
        $this->assertSame('test_nonce_12345678', $lines[3]);
        $this->assertSame(hash('sha256', ''), $lines[4]);
        $this->assertSame('ins_01test', $lines[5]);
    }

    public function testActivateWithLegacyKey(): void
    {
        $mock = new MockHandler([
            // 1. exchange-legacy response
            new Response(200, [], json_encode([
                'code' => 1,
                'msg' => 'ok',
                'data' => [
                    'instance_id' => 'ins_01mock',
                    'activation_id' => 'act_01mock',
                    'challenge' => 'mock_challenge_base64',
                    'expires_at' => '2026-08-14T12:00:00Z',
                ],
            ], JSON_THROW_ON_ERROR)),
            // 2. confirm response
            new Response(200, [], json_encode([
                'code' => 1,
                'msg' => 'ok',
                'data' => [
                    'instance_id' => 'ins_01mock',
                    'domain' => 'pay.example.com',
                    'status' => 'ACTIVE',
                    'activated_at' => '2026-08-14T12:00:00Z',
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new CloudInstanceClient($this->tempIdentityFile, 'https://mock.cloud.cxpay.com', $httpClient);
        $result = $client->activateWithLegacyKey('legacy_key_test', 'pay.example.com');

        $this->assertSame('ins_01mock', $result['instance_id']);
        $this->assertSame('pay.example.com', $result['domain']);
        $this->assertSame('ACTIVE', $result['status']);

        // 验证本地状态已更新为 activated = true
        $identity = $client->getIdentity();
        $this->assertTrue($identity['activated']);
        $this->assertSame('ins_01mock', $identity['instance_id']);
    }

    public function testFetchCatalogWithSignatureHeaders(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'code' => 1,
                'msg' => 'ok',
                'data' => [
                    'plugins' => [
                        [
                            'plugin_id' => 'cxpay.wxpay.cloud_adapter',
                            'name' => '微信云监控适配器',
                            'latest_version' => '1.3.0',
                            'entitled' => true,
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $httpClient = new Client(['handler' => $handlerStack]);

        $client = new CloudInstanceClient($this->tempIdentityFile, 'https://mock.cloud.cxpay.com', $httpClient);

        // 先模拟已激活
        $identity = $client->getIdentity();
        $identity['instance_id'] = 'ins_01active';
        $identity['domain'] = 'pay.example.com';
        $identity['activated'] = true;
        file_put_contents($this->tempIdentityFile, json_encode($identity));

        $catalog = $client->fetchCatalog();
        $this->assertSame(1, $catalog['code']);
        $this->assertSame('cxpay.wxpay.cloud_adapter', $catalog['data']['plugins'][0]['plugin_id']);

        // 检查发出的请求头是否包含规范签名
        $lastRequest = $mock->getLastRequest();
        $this->assertNotNull($lastRequest);
        $this->assertSame('ins_01active', $lastRequest->getHeaderLine('X-CXPAY-Instance'));
        $this->assertNotEmpty($lastRequest->getHeaderLine('X-CXPAY-Signature'));
        $this->assertNotEmpty($lastRequest->getHeaderLine('X-CXPAY-Nonce'));
        $this->assertNotEmpty($lastRequest->getHeaderLine('X-CXPAY-Timestamp'));
    }
}
