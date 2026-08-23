<?php

declare(strict_types=1);

namespace tests\Unit;

use app\controller\admin\CloudPluginMarketController;
use app\service\CloudInstanceClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use support\Request;

final class CloudPluginMarketControllerTest extends TestCase
{
    private string $tempIdentityFile;

    protected function setUp(): void
    {
        $this->tempIdentityFile = sys_get_temp_dir() . '/test_controller_identity_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempIdentityFile)) {
            @unlink($this->tempIdentityFile);
        }
    }

    public function testInstanceStatusReturnsMetadata(): void
    {
        $client = new CloudInstanceClient($this->tempIdentityFile, 'https://mock.cloud.cxpay.com');
        $controller = new CloudPluginMarketController($client);

        $response = $controller->instanceStatus();
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string)$response->rawBody(), true);
        $this->assertSame(1, $data['code']);
        $this->assertNotEmpty($data['data']['public_key']);
        $this->assertNotEmpty($data['data']['fingerprint']);
        $this->assertFalse($data['data']['activated']);
    }

    public function testGetCloudMarketPromptsActivationWhenUnactivated(): void
    {
        $client = new CloudInstanceClient($this->tempIdentityFile, 'https://mock.cloud.cxpay.com');
        $controller = new CloudPluginMarketController($client);

        $response = $controller->getCloudMarket();
        $data = json_decode((string)$response->rawBody(), true);

        $this->assertSame(-1, $data['code']);
        $this->assertSame('CLOUD_INSTANCE_ACTIVATION_REQUIRED', $data['error_code']);
        $this->assertSame('ACTIVATE_INSTANCE', $data['data']['action']);
    }

    public function testGetCloudMarketProxiesCatalogWhenActivated(): void
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
                        ],
                    ],
                ],
            ])),
        ]);

        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new CloudInstanceClient($this->tempIdentityFile, 'https://mock.cloud.cxpay.com', $httpClient);

        // 模拟已激活
        $identity = $client->getIdentity();
        $identity['instance_id'] = 'ins_active_01';
        $identity['domain'] = 'pay.test.com';
        $identity['activated'] = true;
        file_put_contents($this->tempIdentityFile, json_encode($identity));

        $controller = new CloudPluginMarketController($client);
        $response = $controller->getCloudMarket();

        $data = json_decode((string)$response->rawBody(), true);
        $this->assertSame(1, $data['code']);
        $this->assertSame('cxpay.wxpay.cloud_adapter', $data['data']['plugins'][0]['plugin_id']);
    }

    public function testCreatePurchaseOrderAndConfirm(): void
    {
        $client = new CloudInstanceClient($this->tempIdentityFile, 'https://mock.cloud.cxpay.com');
        $controller = new CloudPluginMarketController($client);

        // 构造模拟 Request
        $rawHttp = "POST /api/admin/plugin/order/create HTTP/1.1\r\nHost: cs.fcwan.cn\r\nContent-Type: application/x-www-form-urlencoded\r\n\r\nplugin_id=cxpay.driver.wxpay_app_asst&pay_type=alipay";
        $request = new Request($rawHttp);

        $response = $controller->createPurchaseOrder($request);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string)$response->rawBody(), true);
        $this->assertSame(1, $data['code']);
        $this->assertNotEmpty($data['data']['order_no']);
        $this->assertSame('99.00', $data['data']['money']);
        $this->assertNotEmpty($data['data']['qr_code_content']);

        $orderNo = $data['data']['order_no'];

        // 检查状态 (未支付)
        $reqStatus = new Request("POST /api/admin/plugin/order/status HTTP/1.1\r\nHost: cs.fcwan.cn\r\nContent-Type: application/x-www-form-urlencoded\r\n\r\norder_no={$orderNo}");
        $statusRes = $controller->checkOrderStatus($reqStatus);
        $statusData = json_decode((string)$statusRes->rawBody(), true);
        $this->assertSame(1, $statusData['code']);
        $this->assertFalse($statusData['data']['paid']);

        // ── 安全验证：confirmPayment 在未真实付款时必须拒绝 ─────────────────
        $reqConfirm = new Request("POST /api/admin/plugin/order/confirm HTTP/1.1\r\nHost: cs.fcwan.cn\r\nContent-Type: application/x-www-form-urlencoded\r\n\r\norder_no={$orderNo}");
        $confirmRes = $controller->confirmPayment($reqConfirm);
        $confirmData = json_decode((string)$confirmRes->rawBody(), true);
        // 必须返回 -1，不允许绕过支付直接开通
        $this->assertSame(-1, $confirmData['code'], '安全断言失败：未付款订单不得通过 confirmPayment 开通插件');
        $this->assertSame('PENDING', $confirmData['data']['status'] ?? 'PENDING');

        // ── 模拟支付回调将订单写入 PAID 状态（真实环境由支付网关回调完成） ──
        $orderFile = runtime_path() . "/orders/{$orderNo}.json";
        $orderRaw = json_decode((string)file_get_contents($orderFile), true);
        $orderRaw['status'] = 'PAID';
        $orderRaw['pay_time'] = time();
        file_put_contents($orderFile, json_encode($orderRaw, JSON_UNESCAPED_UNICODE));

        // 再次检查状态 (已支付) — 由轮询检测到
        $statusRes2 = $controller->checkOrderStatus($reqStatus);
        $statusData2 = json_decode((string)$statusRes2->rawBody(), true);
        $this->assertSame(1, $statusData2['code']);
        $this->assertTrue($statusData2['data']['paid']);
    }
}

