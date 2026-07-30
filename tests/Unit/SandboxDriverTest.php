<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use app\payment\Drivers\Sandbox\Driver;

/**
 * 沙箱支付驱动单元测试（任务12）
 *
 * 验证沙箱驱动核心行为：不产生外部请求、正确生成落地页 URL、通道配置校验。
 */
final class SandboxDriverTest extends TestCase
{
    private Driver $driver;

    protected function setUp(): void
    {
        $this->driver = new Driver();
    }

    /**
     * 测试1：pay() 返回沙箱支付 URL，包含 trade_no 和 _sandbox 参数
     */
    public function testPayReturnsSandboxUrl(): void
    {
        $result = $this->driver->pay(
            ['trade_no' => 'CX202607310001', 'out_trade_no' => 'OUT-001', 'money' => '99.99', 'name' => '测试商品'],
            ['sandbox_secret' => 'secret123456789', 'auto_pay_delay' => '0']
        );

        self::assertSame('url', $result['type'], '沙箱驱动 pay_mode 应为 url');
        self::assertStringContainsString('CX202607310001', $result['pay_url'], 'pay_url 应包含 trade_no');
        self::assertStringContainsString('_sandbox=1', $result['pay_url'], 'pay_url 应包含 _sandbox=1 标识');
        self::assertStringContainsString('/api/sandbox/pay', $result['pay_url'], '落地页路径应为 /api/sandbox/pay');
    }

    /**
     * 测试2：notify() 始终返回 success=false（沙箱不走上游回调）
     */
    public function testNotifyAlwaysReturnsFalse(): void
    {
        $result = $this->driver->notify(['anything' => 'here'], []);
        self::assertFalse($result['success'], '沙箱 notify 应始终返回 false');
        self::assertSame('', $result['trade_no']);
    }

    /**
     * 测试3：query() 始终返回 paid=false
     */
    public function testQueryAlwaysReturnsUnpaid(): void
    {
        $result = $this->driver->query('CX202607310001', []);
        self::assertFalse($result['paid'], '沙箱不支持主动查单，始终返回 paid=false');
    }

    /**
     * 测试4：getMeta() 返回正确的 c_type 和 pay_category
     */
    public function testGetMetaReturnsCorrectMetadata(): void
    {
        $meta = $this->driver->getMeta();
        self::assertSame('sandbox_test', $meta['name'], 'c_type 应为 sandbox_test');
        self::assertSame('sandbox', $meta['pay_category'], 'pay_category 应为 sandbox');
        self::assertNotEmpty($meta['inputs'], 'inputs 不应为空');
    }

    /**
     * 测试5：upchannel() 对密钥长度和延迟时间进行正确校验
     */
    public function testUpchannelValidation(): void
    {
        // 密钥过短
        $result = $this->driver->upchannel([], ['sandbox_secret' => 'short', 'auto_pay_delay' => '0']);
        self::assertSame(-1, $result['code'], '密钥过短应返回错误');

        // 延迟时间超出范围
        $result = $this->driver->upchannel([], ['sandbox_secret' => str_repeat('a', 32), 'auto_pay_delay' => '999']);
        self::assertSame(-1, $result['code'], '延迟超过 300 秒应返回错误');

        // 合法配置
        $result = $this->driver->upchannel([], ['sandbox_secret' => str_repeat('a', 32), 'auto_pay_delay' => '5']);
        self::assertArrayNotHasKey('code', $result, '合法配置应返回规范化后的 config 数组，不含 code');
        self::assertSame('5', $result['auto_pay_delay']);
    }

    /**
     * 测试6：pay() 中 amount 字段精确透传
     */
    public function testPayAmountIsPreservedExactly(): void
    {
        $result = $this->driver->pay(
            ['trade_no' => 'CX001', 'out_trade_no' => 'OUT-001', 'money' => '0.01'],
            ['sandbox_secret' => str_repeat('k', 32)]
        );
        self::assertSame('0.01', $result['amount'], '最小金额 0.01 应精确透传');
    }
}
