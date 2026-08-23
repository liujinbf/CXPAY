<?php

declare(strict_types=1);

namespace tests\Unit;

use CloudControl\Payment\Application\PaymentService;
use CloudControl\Payment\Infrastructure\PdoPaymentOrderRepository;
use CloudControl\PluginMarket\Application\PluginMarketService;
use CloudControl\PluginMarket\Port\PluginMarketRepository;
use CloudControl\Shared\Clock\Clock;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class CloudPluginPricingTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        // 构造 SQLite 内存数据库模拟云端数据库
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("
            CREATE TABLE cloud_plugins (
                id TEXT PRIMARY KEY,
                plugin_id TEXT UNIQUE,
                name TEXT,
                description TEXT,
                latest_version TEXT,
                publisher TEXT,
                status TEXT,
                manifest_json TEXT,
                created_at TEXT,
                updated_at TEXT
            );

            CREATE TABLE cloud_plugin_entitlements (
                id TEXT PRIMARY KEY,
                tenant_id TEXT,
                plugin_id TEXT,
                status TEXT,
                expires_at TEXT,
                created_at TEXT,
                updated_at TEXT,
                UNIQUE(tenant_id, plugin_id)
            );

            CREATE TABLE cloud_payment_orders (
                id TEXT PRIMARY KEY,
                order_no TEXT UNIQUE,
                trade_no TEXT,
                instance_id TEXT,
                tenant_id TEXT,
                plugin_id TEXT,
                pay_channel TEXT,
                amount REAL,
                status TEXT,
                qr_code_url TEXT,
                notify_data TEXT,
                paid_at TEXT,
                closed_at TEXT,
                expire_at TEXT,
                created_at TEXT,
                updated_at TEXT
            );
        ");

        // 插入测试插件数据（含月费和永久定价）
        $manifestJson = json_encode([
            'pricing' => [
                'price_month' => '29.00',
                'price_forever' => '129.00',
                'price_agent_month' => '12.00',
                'price_agent_forever' => '49.00',
                'allow_resell' => true,
            ],
            'c_type' => 'wechat_dy_bill',
            'category' => 'wxpay',
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $this->pdo->prepare("
            INSERT INTO cloud_plugins (id, plugin_id, name, description, latest_version, publisher, status, manifest_json, created_at, updated_at)
            VALUES ('01', 'cxpay.driver.wechat_dy_bill', '微信店员小账本', '描述', '2.1.0', 'cxpay.official', 'ACTIVE', :manifest, '2026-08-23 00:00:00', '2026-08-23 00:00:00')
        ");
        $stmt->execute(['manifest' => $manifestJson]);
    }

    public function testPaymentServiceResolvesMonthlyAndForeverPrices(): void
    {
        $repo = new PdoPaymentOrderRepository($this->pdo);
        $service = new PaymentService($repo, $this->pdo);

        $priceMonth = $service->resolvePrice('cxpay.driver.wechat_dy_bill', 'month');
        $this->assertEquals(29.00, $priceMonth);

        $priceForever = $service->resolvePrice('cxpay.driver.wechat_dy_bill', 'forever');
        $this->assertEquals(129.00, $priceForever);
    }

    public function testCatalogOutputsMonthlyAndPermanentPricing(): void
    {
        $mockRepo = $this->createMock(PluginMarketRepository::class);
        $mockRepo->method('listActivePlugins')->willReturn([
            new \CloudControl\PluginMarket\Domain\Plugin(
                id: '01',
                pluginId: 'cxpay.driver.wechat_dy_bill',
                name: '微信店员小账本',
                description: '描述',
                latestVersion: '2.1.0',
                publisher: 'cxpay.official',
                status: 'ACTIVE',
                manifestJson: [
                    'pricing' => [
                        'price_month' => '29.00',
                        'price_forever' => '129.00',
                        'price_agent_month' => '12.00',
                        'price_agent_forever' => '49.00',
                    ],
                    'c_type' => 'wechat_dy_bill',
                ],
                createdAt: new DateTimeImmutable('2026-08-23T00:00:00Z'),
                updatedAt: new DateTimeImmutable('2026-08-23T00:00:00Z')
            ),
        ]);
        $mockRepo->method('isTenantEntitled')->willReturn(false);

        $mockClock = $this->createMock(Clock::class);
        $mockClock->method('now')->willReturn(new DateTimeImmutable('2026-08-23T00:00:00Z'));

        $service = new PluginMarketService($mockRepo, $mockClock);
        $catalog = $service->getCatalog('tenant_test_123');

        $this->assertNotEmpty($catalog['plugins']);
        $plugin = $catalog['plugins'][0];

        $this->assertSame('29.00', $plugin['price_month']);
        $this->assertSame('129.00', $plugin['price_forever']);
        $this->assertSame('12.00', $plugin['agent_price_month']);
        $this->assertSame('49.00', $plugin['agent_price_forever']);
        $this->assertSame('月费 ¥29.00 / 永久 ¥129.00', $plugin['price_text']);
        $this->assertFalse($plugin['is_free']);
    }

    public function testLocalGetCloudMarketOutputsBothPrices(): void
    {
        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'code' => 1,
                'msg' => 'ok',
                'data' => [
                    'plugins' => [
                        [
                            'plugin_id' => 'cxpay.driver.wechat_dy_bill',
                            'name' => '微信店员小账本免挂监控插件',
                            'price' => '129.00',
                            'price_month' => '29.00',
                            'price_forever' => '129.00',
                            'price_text' => '月费 ¥29.00 / 永久 ¥129.00',
                        ],
                    ],
                ],
            ])),
        ]);

        $httpClient = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($mock)]);
        $tempIdFile = sys_get_temp_dir() . '/test_pricing_ident_' . bin2hex(random_bytes(4)) . '.json';
        $client = new \app\service\CloudInstanceClient($tempIdFile, 'https://mock.cloud.cxpay.com', $httpClient);

        $identity = $client->getIdentity();
        $identity['instance_id'] = 'ins_active_01';
        $identity['domain'] = 'pay.test.com';
        $identity['activated'] = true;
        file_put_contents($tempIdFile, json_encode($identity));

        $controller = new \app\controller\admin\CloudPluginMarketController($client);
        $response = $controller->getCloudMarket();
        $data = json_decode((string)$response->rawBody(), true);

        $this->assertSame(1, $data['code']);
        $list = $data['data']['list'];
        $found = null;
        foreach ($list as $item) {
            if ($item['plugin_id'] === 'cxpay.driver.wechat_dy_bill') {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('29.00', $found['price_month']);
        $this->assertSame('129.00', $found['price_forever']);
        $this->assertStringContainsString('月费', $found['price_text']);

        @unlink($tempIdFile);
    }
}
