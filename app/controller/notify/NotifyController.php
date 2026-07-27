<?php

declare(strict_types=1);

namespace app\controller\notify;

use app\service\OrderService;
use app\payment\PaymentManager;
use app\model\Channel;
use support\Authcode;
use support\Response;
use Throwable;

/**
 * 上游支付通道异步回调监听控制器
 */
class NotifyController
{
    protected OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    /**
     * 处理上游回调
     *
     * @param object $request HTTP 请求
     * @param string $cType   通道驱动标识，如 alipay_official / wxpay_official
     */
    public function index(object $request, string $cType): Response
    {
        try {
            $params = $request->get() + $request->post();

            // 1. 实例化上游驱动
            $driver = PaymentManager::make($cType);

            // 2. 从数据库获取通道真实配置（解密后传给驱动，驱动才能用密钥做验签）
            $config = $this->resolveChannelConfig($cType);

            // 3. 调用驱动进行验签与状态解析
            $result = $driver->notify($params, $config);

            if (!empty($result['success']) && !empty($result['out_trade_no'])) {
                // 4. 驱动验签成功，更新系统订单状态并向商户发送回调通知
                $this->orderService->markAsPaid(
                    $result['out_trade_no'],
                    $result['trade_no'] ?? '',
                    (float)($result['amount'] ?? 0)
                );

                return response('success');
            }

            return response('fail');
        } catch (Throwable $e) {
            // 避免将内部异常信息暴露给上游，仅写错误日志
            error_log('[NotifyController] cType=' . $cType . ' error: ' . $e->getMessage());
            return response('fail');
        }
    }

    /**
     * 从数据库查询指定 cType 通道的解密配置
     * 若数据库不可用或无匹配记录则返回空数组（驱动内部自行处理）
     *
     * @param  string $cType 通道驱动标识
     * @return array  解密后的配置键值对
     */
    private function resolveChannelConfig(string $cType): array
    {
        try {
            $channel = Channel::where('c_type', $cType)
                ->where('status', 1)
                ->orderBy('id', 'desc')
                ->first();

            if (!$channel || empty($channel->config)) {
                return [];
            }

            $raw = is_string($channel->config)
                ? (json_decode($channel->config, true) ?: [])
                : (array)$channel->config;

            // 用 Authcode 解密每个字段（若未加密则原样返回）
            $authcode  = new Authcode();
            $decrypted = [];
            foreach ($raw as $k => $v) {
                $decrypted[$k] = is_string($v) ? ($authcode->decrypt($v) ?: $v) : $v;
            }

            return $decrypted;
        } catch (Throwable) {
            return [];
        }
    }
}
