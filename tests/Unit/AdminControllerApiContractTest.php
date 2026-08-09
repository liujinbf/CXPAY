<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\admin\AdminAuthController;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use support\Request;

final class AdminControllerApiContractTest extends TestCase
{
    public function testAuthenticationRejectsEmptyCredentials(): void
    {
        self::assertTrue(class_exists(AdminAuthController::class), '认证控制器尚未迁移');
        $payload = $this->decode((new AdminAuthController())->login($this->postRequest([])));

        self::assertSame(-1, $payload['code']);
        self::assertSame('管理员账号与密码不能为空', $payload['msg']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDashboardReturnsStableFallbackWhenInfrastructureIsUnavailable(): void
    {
        $class = \app\controller\admin\AdminDashboardController::class;
        self::assertTrue(class_exists($class), '仪表盘控制器尚未迁移');
        $payload = $this->decode((new $class())->dashboard($this->postRequest([])));

        self::assertSame(1, $payload['code']);
        self::assertSame('0.00', $payload['data']['total_amount']);
        self::assertSame(0, $payload['data']['total_orders']);
        self::assertSame('100.00%', $payload['data']['success_rate']);
        self::assertSame('HEALTHY', $payload['data']['metrics']['db_pool']);
    }

    public function testChannelSaveRejectsPermanentlyRemovedDriver(): void
    {
        $class = \app\controller\admin\AdminChannelConfigController::class;
        self::assertTrue(class_exists($class), '平台通道配置控制器尚未迁移');
        $payload = $this->decode((new $class())->saveChannelConfig(
            $this->postRequest(['c_type' => 'alipay_official'])
        ));

        self::assertSame(-1, $payload['code']);
        self::assertSame('该支付驱动已永久移除，不能创建或修改通道', $payload['msg']);
    }

    public function testMerchantSaveRejectsInvalidNameAndRate(): void
    {
        $class = \app\controller\admin\AdminMerchantController::class;
        self::assertTrue(class_exists($class), '商户管理控制器尚未迁移');
        $payload = $this->decode((new $class())->saveMerchant(
            $this->postRequest(['name' => ' ', 'rate' => '2'])
        ));

        self::assertSame(-1, $payload['code']);
        self::assertSame('商户名称、密钥或费率格式不合法', $payload['msg']);
    }

    public function testSecuritySaveRejectsShortVerificationCode(): void
    {
        $class = \app\controller\admin\AdminSecurityController::class;
        self::assertTrue(class_exists($class), '安全设置控制器尚未迁移');
        $payload = $this->decode((new $class())->saveSecurityConfig(
            $this->postRequest(['verify_code' => '123'])
        ));

        self::assertSame(-1, $payload['code']);
        self::assertSame('验证码长度须在4至32位之间', $payload['msg']);
    }

    public function testTemplateSaveRejectsTraversalName(): void
    {
        $class = \app\controller\admin\MerchantTemplateController::class;
        self::assertTrue(class_exists($class), '主页模板控制器尚未迁移');
        $payload = $this->decode((new $class())->saveTemplate(
            $this->postRequest(['template' => '../bad'])
        ));

        self::assertSame(-1, $payload['code']);
        self::assertSame('主页模板不存在或名称不合法', $payload['msg']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testForceNotifyReturnsNotFoundForUnknownOrder(): void
    {
        $class = \app\controller\admin\OrderAdminController::class;
        self::assertTrue(method_exists($class, 'forceNotifyOrder'), '人工补单尚未迁移');

        $db = new DB();
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        $db->schema()->create('cx_order', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('trade_no')->unique();
            $table->unsignedTinyInteger('status')->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('channel_id')->default(0);
        });
        $db->schema()->create('cx_audit_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('operator');
            $table->string('action');
            $table->text('context');
            $table->string('result');
            $table->string('ip');
            $table->unsignedInteger('created_at');
        });

        $payload = $this->decode((new $class())->forceNotifyOrder(
            $this->postRequest(['trade_no' => 'ADMIN-MISSING'])
        ));

        self::assertSame(-1, $payload['code']);
        self::assertSame('订单不存在', $payload['msg']);
        self::assertSame('force_pay', $db->table('cx_audit_log')->value('action'));
        self::assertSame('fail', $db->table('cx_audit_log')->value('result'));
    }

    private function postRequest(array $data): Request
    {
        $body = http_build_query($data);
        return new Request(
            "POST / HTTP/1.1\r\n"
            . "Host: pay.example.com\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body
        );
    }

    private function decode(string $json): array
    {
        return json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    }
}
