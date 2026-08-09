<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\admin\AdminAuthController;
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
