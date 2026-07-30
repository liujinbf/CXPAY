<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\service\AlertNotificationService;
use PHPUnit\Framework\TestCase;

final class AlertNotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        \Illuminate\Database\Capsule\Manager::schema()->create('cx_config', function ($table) {
            $table->string('name')->primary();
            $table->text('value')->nullable();
            $table->string('title')->default('');
        });
    }

    public function testConfigReadWrite(): void
    {
        $service = new AlertNotificationService();

        // 默认配置结构
        $config = $service->getAdminConfig();
        self::assertArrayHasKey('enabled', $config);
        self::assertArrayHasKey('events', $config);
        self::assertArrayHasKey('email_config', $config);
        self::assertArrayHasKey('wxwork_config', $config);
        self::assertArrayHasKey('webhook_config', $config);

        // 保存并读取测试
        $res = $service->saveAdminConfig([
            'enabled' => true,
            'events' => ['admin_login' => true],
            'wxwork_config' => ['enabled' => true, 'webhook_url' => 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=test']
        ]);
        self::assertSame(1, $res['code']);

        $updated = $service->getAdminConfig();
        self::assertTrue($updated['enabled']);
        self::assertTrue($updated['events']['admin_login'] ?? false);
        self::assertTrue($updated['wxwork_config']['enabled'] ?? false);
    }

}
