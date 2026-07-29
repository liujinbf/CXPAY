<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Channel;
use app\payment\PaymentManager;
use app\service\CallbillService;
use support\AppasstProtocol;
use support\Authcode;
use support\Request;
use support\Response;
use Throwable;

/**
 * 挂机助手账单上报接口。
 */
class AppasstController
{
    private const TIMESTAMP_TOLERANCE = 300;
    private const NONCE_TTL = 600;

    protected CallbillService $callbillService;

    public function __construct()
    {
        $this->callbillService = new CallbillService();
    }

    /**
     * v2签名原文：
     * version|channel_id|device_id|event|pay_type|money|source_bill_id|occurred_at|timestamp|nonce|client_version
     */
    public function push(Request $request): Response
    {
        try {
            $params = $request->get() + $request->post();
            $version = trim((string)($params['version'] ?? ''));
            $channelId = (int)($params['channel_id'] ?? 0);
            $deviceId = trim((string)($params['device_id'] ?? ''));
            $event = trim((string)($params['event'] ?? 'bill'));
            $payType = trim((string)($params['pay_type'] ?? ''));
            $moneyRaw = trim((string)($params['money'] ?? ''));
            $money = preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $moneyRaw)
                ? number_format((float)$moneyRaw, 2, '.', '')
                : '0.00';
            $timestamp = (int)($params['timestamp'] ?? 0);
            $nonce = trim((string)($params['nonce'] ?? ''));
            $sign = strtolower(trim((string)($params['sign'] ?? '')));
            $remark = trim((string)($params['remark'] ?? ''));
            $sourceBillId = trim((string)($params['source_bill_id'] ?? ''));
            $occurredAt = (int)($params['occurred_at'] ?? 0);
            $clientVersion = trim((string)($params['client_version'] ?? ''));

            if ($version !== AppasstProtocol::VERSION
                || $channelId <= 0
                || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $deviceId)
                || !in_array($event, ['bill', 'heartbeat'], true)
                || !in_array($payType, ['alipay', 'wxpay', 'qqpay'], true)
                || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $clientVersion)
                || mb_strlen($remark) > 255) {
                return $this->fail('协议版本、通道、设备、事件或客户端版本不合法', 400);
            }
            if (($event === 'bill' && ((float)$money <= 0 || $moneyRaw === ''))
                || ($event === 'heartbeat' && $money !== '0.00')) {
                return $this->fail('账单事件必须提供有效到账金额', 400);
            }
            if ($event === 'bill') {
                if (!preg_match('/^[A-Za-z0-9_.:-]{16,128}$/', $sourceBillId)) {
                    return $this->fail('账单事件必须提供稳定且合法的 source_bill_id', 400);
                }
                if ($occurredAt <= 0 || $occurredAt > time() + self::TIMESTAMP_TOLERANCE
                    || $occurredAt < time() - 604800) {
                    return $this->fail('账单发生时间无效或超出允许范围', 400);
                }
            } elseif ($sourceBillId !== '' || $occurredAt !== 0) {
                return $this->fail('心跳事件不得携带账单标识或账单发生时间', 400);
            }
            if (abs(time() - $timestamp) > self::TIMESTAMP_TOLERANCE) {
                return $this->fail('请求时间戳无效或已过期', 403);
            }
            if (!preg_match('/^[A-Za-z0-9_-]{16,128}$/', $nonce)
                || !preg_match('/^[a-f0-9]{64}$/', $sign)) {
                return $this->fail('nonce 或签名格式不正确', 400);
            }

            $channel = Channel::where('id', $channelId)->where('status', 1)->first();
            if (!$channel) {
                return $this->fail('支付通道不存在或未启用', 403);
            }
            if (!hash_equals((string)$channel->pay_category, $payType)) {
                return $this->fail('上报支付类型与通道分类不一致', 403);
            }
            if (!PaymentManager::has((string)$channel->c_type)
                || !PaymentManager::requiresHeartbeat((string)$channel->c_type)) {
                return $this->fail('该通道不接受监控助手上报', 403);
            }
            $config = $this->decryptConfig((string)$channel->config);
            $secret = trim((string)($config['notify_secret'] ?? ''));
            if (strlen($secret) < 32 || strlen($secret) > 128) {
                return $this->fail('通道未配置账单上报密钥', 403);
            }
            $allowedDeviceId = trim((string)($config['device_id'] ?? ''));
            if ($allowedDeviceId === '' || !hash_equals($allowedDeviceId, $deviceId)) {
                return $this->fail('上报设备未绑定到当前通道', 403);
            }

            $signedParams = [
                'version' => $version,
                'channel_id' => $channelId,
                'device_id' => $deviceId,
                'event' => $event,
                'pay_type' => $payType,
                'money' => $money,
                'source_bill_id' => $sourceBillId,
                'occurred_at' => $occurredAt,
                'timestamp' => $timestamp,
                'nonce' => $nonce,
                'client_version' => $clientVersion,
            ];
            if (!AppasstProtocol::verify($signedParams, $secret, $sign)) {
                return $this->fail('账单上报签名校验失败', 403);
            }

            try {
                $redis = \Webman\Redis\Client::connection();
                $stored = $redis->set(
                    'cx:appasst_nonce:' . $channelId . ':' . $nonce,
                    '1',
                    ['NX', 'EX' => self::NONCE_TTL]
                );
            } catch (Throwable $e) {
                error_log('[AppasstController] Redis nonce 校验失败: ' . $e->getMessage());
                return $this->fail('防重放服务暂时不可用', 503);
            }
            if ($stored !== true && $stored !== 'OK') {
                return $this->fail('nonce 已使用，请勿重复上报', 409);
            }

            $channel->online_status = 1;
            $channel->last_heartbeat_time = time();
            $channel->save();
            if ($event === 'heartbeat') {
                return json(['code' => 1, 'msg' => '心跳上报成功']);
            }

            $callbill = $this->callbillService->processPush(
                mb_substr((string)$channel->c_type, 0, 50),
                $deviceId,
                (float)$money,
                $remark,
                $channelId,
                $sourceBillId,
                $occurredAt,
                hash('sha256', (string)$channel->c_type . '|' . $money . '|' . $remark),
                $clientVersion
            );

            return json([
                'code' => !empty($callbill['success']) ? 1 : -1,
                'msg' => $callbill['msg'] ?? '账单上报并匹配成功',
                'data' => $callbill,
            ]);
        } catch (Throwable $e) {
            error_log('[AppasstController] 上报失败: ' . $e->getMessage());
            return $this->fail('账单上报处理失败', 500);
        }
    }

    private function decryptConfig(string $raw): array
    {
        $config = json_decode($raw, true) ?: [];
        $authcode = new Authcode();
        foreach ($config as $key => $value) {
            if (is_string($value) && $value !== '') {
                $config[$key] = $authcode->decryptStored($value);
            }
        }
        return $config;
    }

    private function fail(string $message, int $status): Response
    {
        return json(['code' => -1, 'msg' => $message])->withStatus($status);
    }
}
