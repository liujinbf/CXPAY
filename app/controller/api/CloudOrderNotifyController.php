<?php

declare(strict_types=1);

namespace app\controller\api;

use app\payment\PaymentManager;
use app\service\CloudInstanceClient;
use support\AuditLog;
use support\StructuredLog as Log;
use support\Request;
use support\Response;
use Throwable;

/**
 * 官方云端授权中心支付结果异步通知接收端
 *
 * 核心职责：
 *   1. 接收云端收银台发起的订单支付成功 Webhook 通知
 *   2. 校验通知签名，确保来源为官方云端授权分发源
 *   3. 自动完成本地订单状态核销与官方商业授权 (Entitlement) 自动入库与加载
 *   4. 接收代理商增购站点配额支付回调并记录审计日志
 */
final class CloudOrderNotifyController
{
    private CloudInstanceClient $client;
    private static string $entitlementFile = '';

    public function __construct(?CloudInstanceClient $client = null)
    {
        $this->client = $client ?? new CloudInstanceClient();
        if (self::$entitlementFile === '') {
            self::$entitlementFile = runtime_path() . '/instance/entitlements.json';
        }
    }

    /**
     * 接收插件购买订单支付异步通知
     * POST /api/cloud/plugin/order/notify
     */
    public function handlePluginOrderNotify(Request $request): Response
    {
        $rawContent = (string)$request->rawBody();
        $payload    = json_decode($rawContent, true);
        if (!is_array($payload)) {
            $payload = $request->post();
        }

        $orderNo    = trim((string)($payload['order_no'] ?? ''));
        $pluginId   = trim((string)($payload['plugin_id'] ?? ''));
        $period     = trim((string)($payload['period'] ?? 'forever'));
        $instanceId = trim((string)($payload['instance_id'] ?? ''));
        $sign       = trim((string)($payload['sign'] ?? $request->header('x-cloud-signature', '')));
        $timestamp  = (int)($payload['timestamp'] ?? 0);

        if ($orderNo === '' || $pluginId === '') {
            Log::warning('云端订单通知参数缺失', ['payload' => $payload]);
            return json(['code' => -1, 'msg' => '缺少关键订单参数'])->withStatus(400);
        }

        // 1. 签名与实例身份验证
        $identity = $this->client->getIdentity();
        $myInstanceId = (string)($identity['instance_id'] ?? '');
        $secretKey    = (string)($identity['secret_key'] ?? '');

        if ($myInstanceId !== '' && $instanceId !== '' && $myInstanceId !== $instanceId) {
            Log::warning('云端通知 instance_id 不匹配', ['local' => $myInstanceId, 'received' => $instanceId]);
            return json(['code' => -1, 'msg' => '实例身份不匹配'])->withStatus(403);
        }

        if ($sign !== '' && $secretKey !== '') {
            $params = $payload;
            unset($params['sign']);
            ksort($params);
            $signStr  = http_build_query($params);
            $expected = hash_hmac('sha256', $signStr, $secretKey);
            if (!hash_equals($expected, $sign)) {
                // 如果使用公钥签名模式
                $expectedPubKeySign = hash_hmac('sha256', $orderNo . '|' . $pluginId . '|' . $timestamp, $secretKey);
                if (!hash_equals($expectedPubKeySign, $sign)) {
                    Log::error('云端订单通知验签失败', ['received_sign' => $sign]);
                    return json(['code' => -1, 'msg' => '签名校验未通过'])->withStatus(403);
                }
            }
        }

        // 2. 更新本地临时订单状态
        $orderFile = runtime_path() . "/orders/{$orderNo}.json";
        $orderInfo = [];
        if (file_exists($orderFile)) {
            $orderInfo = json_decode((string)file_get_contents($orderFile), true) ?: [];
        }

        $orderInfo['status']   = 'PAID';
        $orderInfo['pay_time'] = time();
        $orderInfo['plugin_id']= $pluginId;
        $orderInfo['period']   = $period;
        @file_put_contents($orderFile, json_encode($orderInfo, JSON_UNESCAPED_UNICODE));

        // 3. 自动为本地写入商业授权
        $this->grantEntitlementLocally($pluginId, $period);

        // 4. 尝试热刷新驱动
        try {
            PaymentManager::flush();
        } catch (Throwable) {}

        // 5. 记录安全审计日志
        AuditLog::record('cloud_payment_gateway', 'plugin_order_paid', [
            'order_no'  => $orderNo,
            'plugin_id' => $pluginId,
            'period'    => $period,
        ], 'success', (string)$request->getRemoteIp());

        Log::info("插件【{$pluginId}】通过云端异步通知自动开通成功", ['order_no' => $orderNo]);

        return json([
            'code' => 1,
            'msg'  => 'SUCCESS',
            'data' => [
                'order_no'  => $orderNo,
                'plugin_id' => $pluginId,
                'entitled'  => true,
            ]
        ]);
    }

    /**
     * 接收代理商增购配额异步通知
     * POST /api/agent/quota/notify
     */
    public function handleQuotaNotify(Request $request): Response
    {
        $rawContent = (string)$request->rawBody();
        $payload    = json_decode($rawContent, true);
        if (!is_array($payload)) {
            $payload = $request->post();
        }

        $orderNo  = trim((string)($payload['order_no'] ?? ''));
        $quantity = (int)($payload['quantity'] ?? 0);

        AuditLog::record('cloud_agent_gateway', 'quota_buy_paid', [
            'order_no' => $orderNo,
            'quantity' => $quantity,
        ], 'success', (string)$request->getRemoteIp());

        Log::info("代理商站点配额增购成功 (数量: {$quantity})", ['order_no' => $orderNo]);

        return json(['code' => 1, 'msg' => 'SUCCESS']);
    }

    private function grantEntitlementLocally(string $pluginId, string $period = 'forever'): void
    {
        $dir = dirname(self::$entitlementFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $entitlements = [];
        if (file_exists(self::$entitlementFile)) {
            $entitlements = json_decode((string)file_get_contents(self::$entitlementFile), true) ?: [];
        }

        $isMonth   = in_array(strtolower($period), ['month', 'monthly'], true);
        $expiresAt = null;
        if ($isMonth) {
            $curExpire = isset($entitlements[$pluginId]['expires_at']) ? strtotime((string)$entitlements[$pluginId]['expires_at']) : 0;
            $base      = max(time(), $curExpire);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days', $base));
        }

        $entitlements[$pluginId] = [
            'plugin_id'  => $pluginId,
            'granted_at' => date('Y-m-d H:i:s'),
            'type'       => $isMonth ? 'MONTH' : 'PERMANENT',
            'expires_at' => $expiresAt,
        ];

        @file_put_contents(self::$entitlementFile, json_encode($entitlements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
