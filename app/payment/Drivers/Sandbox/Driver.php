<?php

declare(strict_types=1);

namespace app\payment\Drivers\Sandbox;

use app\payment\Contracts\PaymentDriverInterface;

/**
 * 沙箱测试通道驱动（Sandbox Driver）
 *
 * 用途：
 *   提供免真实扣款的模拟支付环境，供商户快速联调对接而无需绑定真实收款账号。
 *
 * 工作机制：
 *   1. pay()     — 生成模拟支付页面 URL（/api/sandbox/pay?trade_no=xxx）
 *   2. notify()  — 始终返回 success=false（沙箱由专用端点手动触发，不走上游回调）
 *   3. query()   — 返回 paid=false（沙箱状态由平台内部维护）
 *
 * 安全约束：
 *   - 本驱动不接受任何真实资金流向，不产生任何外部 HTTP 请求
 *   - c_type 固定为 sandbox_test，pay_category 为 sandbox
 *   - 管理员后台保存通道时 pay_category 校验会拦截（沙箱不在 wxpay/alipay/qqpay 范围）
 *     → 平台通道需由 patch_v8.sql 或安装脚本预置，而非通过后台 UI 创建
 */
class Driver implements PaymentDriverInterface
{
    /** 沙箱支付落地页基础路径，前端静态页面由各平台自行实现 */
    private const SANDBOX_PAY_PATH = '/api/sandbox/pay';

    public function pay(array $params, array $config): array
    {
        $tradeNo = (string)($params['trade_no'] ?? '');
        $amount  = (string)($params['money'] ?? '0.00');
        $subject = urlencode((string)($params['name'] ?? '沙箱测试订单'));

        // 生成沙箱支付 URL，携带订单信息供前端模拟支付页面展示
        $payUrl = self::SANDBOX_PAY_PATH
            . '?trade_no=' . rawurlencode($tradeNo)
            . '&amount=' . rawurlencode($amount)
            . '&subject=' . $subject
            . '&_sandbox=1';

        return [
            'type'         => 'url',
            'trade_no'     => $tradeNo,
            'out_trade_no' => (string)($params['out_trade_no'] ?? ''),
            'amount'       => $amount,
            'pay_url'      => $payUrl,
        ];
    }

    /**
     * 沙箱不走上游异步回调，统一返回失败。
     * 沙箱订单核销由 POST /api/sandbox/complete 端点手动触发。
     */
    public function notify(array $params, array $config): array
    {
        return [
            'success'      => false,
            'out_trade_no' => '',
            'trade_no'     => '',
            'amount'       => 0.0,
        ];
    }

    /** 沙箱通道不支持主动查单。 */
    public function query(string $tradeNo, array $config): array
    {
        return ['paid' => false];
    }

    public function getMeta(): array
    {
        return [
            'name'            => 'sandbox_test',
            'title'           => '沙箱测试通道',
            'description'     => '免真实扣款的模拟支付通道，仅供商户联调测试使用，不产生任何真实资金流向',
            'pay_category'    => 'sandbox',
            'collection_mode' => 'sandbox',
            'monitor_mode'    => 'none',
            'deprecated'      => false,
            'inputs'          => [
                [
                    'name'     => 'sandbox_secret',
                    'title'    => '沙箱触发密钥（用于手动核销接口鉴权，16-64位）',
                    'type'     => 'password',
                    'required' => true,
                    'default'  => '',
                ],
                [
                    'name'     => 'auto_pay_delay',
                    'title'    => '自动核销延迟（秒，0=不自动核销，最大300）',
                    'type'     => 'string',
                    'required' => false,
                    'default'  => '0',
                ],
            ],
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        $secret = trim((string)($config['sandbox_secret'] ?? ''));
        if ($secret === '' || strlen($secret) < 16 || strlen($secret) > 64) {
            return ['code' => -1, 'msg' => '沙箱触发密钥长度须在16至64位之间'];
        }

        $delay = (int)($config['auto_pay_delay'] ?? 0);
        if ($delay < 0 || $delay > 300) {
            return ['code' => -1, 'msg' => '自动核销延迟须在0至300秒之间'];
        }

        $config['sandbox_secret']  = $secret;
        $config['auto_pay_delay']  = (string)$delay;
        return $config;
    }
}
