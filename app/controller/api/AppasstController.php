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
    private const TIMESTAMP_TOLERANCE = 600;
    private const NONCE_TTL = 600;

    protected CallbillService $callbillService;

    public function __construct()
    {
        $this->callbillService = new CallbillService();
    }

    /**
     * App 版本检查与热更新信息接口
     */
    public function version(Request $request): Response
    {
        $host = $request->header('host', 'cs.fcwan.cn');
        $scheme = $request->header('x-forwarded-proto', 'https');
        $baseUrl = "{$scheme}://{$host}";

        return json([
            'code' => 1,
            'msg' => 'ok',
            'data' => [
                'latest_version' => '1.3.1',
                'version_code' => 131,
                'download_url' => "{$baseUrl}/download/CXPayAssistant.apk",
                'update_log' => "• 优化主界面顶部布局：消除文字折行与设备码截断\n• 强化系统级永久保活与分通道收款统计\n• 支持应用内一键自动检测更新与下载安装",
                'force_update' => false,
                'release_time' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * 官方安装包直接下载入口
     */
    public function downloadApk(Request $request): Response
    {
        $file = public_path() . '/download/CXPayAssistant.apk';
        if (!file_exists($file)) {
            $file = base_path() . '/public/download/CXPayAssistant.apk';
        }
        if (!file_exists($file)) {
            return response('CXPayAssistant.apk file not found', 404);
        }
        return response()->download($file, 'CXPayAssistant.apk');
    }

    /**
     * 挂机助手推送入口
     */
    public function push(Request $request): Response
    {
        try {
            $params = $request->get() + $request->post();
            
            // 详细记录接收到的原始请求
            error_log('[AppasstController] 收到上报请求: ' . json_encode($params, JSON_UNESCAPED_UNICODE));

            $version = trim((string)($params['version'] ?? '2'));
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

            // 毫秒时间戳自动降为秒
            if ($timestamp > 100000000000) {
                $timestamp = (int)($timestamp / 1000);
            }
            if ($occurredAt > 100000000000) {
                $occurredAt = (int)($occurredAt / 1000);
            }
            if ($occurredAt <= 0) {
                $occurredAt = time();
            }

            if (!in_array($version, ['2', 'v2', '1', 'v1', ''], true)
                || $channelId <= 0
                || !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $deviceId)
                || !in_array($event, ['bill', 'heartbeat'], true)
                || !in_array($payType, ['alipay', 'wxpay', 'qqpay'], true)
                || mb_strlen($remark) > 255) {
                error_log("[AppasstController] 基础参数非法: version={$version}, channelId={$channelId}, deviceId={$deviceId}, event={$event}, payType={$payType}");
                return $this->fail('协议版本、通道、设备、事件或客户端版本不合法', 400);
            }

            if (($event === 'bill' && ((float)$money <= 0 || $moneyRaw === ''))
                || ($event === 'heartbeat' && $money !== '0.00')) {
                error_log("[AppasstController] 金额参数不合法: event={$event}, money={$money}, moneyRaw={$moneyRaw}");
                return $this->fail('账单事件必须提供有效到账金额', 400);
            }

            if ($event === 'bill' && $sourceBillId === '') {
                $sourceBillId = 'BILL_' . time() . '_' . substr(md5($money . $remark . $nonce), 0, 16);
            }

            $rawChannelId = $channelId;
            $channel = Channel::where('id', $channelId)->where('status', 1)->first();
            if (!$channel) {
                // 智能通道路由兜底：若上报的 channel_id 不存在（例如商户删除了旧通道重建了新通道）
                // 则根据 pay_category 智能寻找当前启用的有效挂机通道
                $fallbackChannel = Channel::where('pay_category', $payType)
                    ->where('status', 1)
                    ->where('c_type', 'like', '%app_asst%')
                    ->orderBy('id', 'desc')
                    ->first();
                if ($fallbackChannel) {
                    error_log("[AppasstController] 触发智能通道路由兜底: 原通道ID {$channelId} 不存在，自动切换为活跃通道 ID {$fallbackChannel->id}");
                    $channel = $fallbackChannel;
                    $channelId = $channel->id;
                } else {
                    error_log("[AppasstController] 通道不存在或未启用: ID={$channelId}");
                    return $this->fail('支付通道不存在或未启用', 403);
                }
            }

            $config = $this->decryptConfig((string)$channel->config);
            $secret = trim((string)($config['notify_secret'] ?? ''));
            if (strlen($secret) < 16) {
                error_log("[AppasstController] 通道未配置上报密钥: ID={$channelId}");
                return $this->fail('通道未配置账单上报密钥', 403);
            }

            $allowedDeviceId = trim((string)($config['device_id'] ?? ''));
            if ($allowedDeviceId !== '' && !hash_equals($allowedDeviceId, $deviceId)) {
                error_log("[AppasstController] 设备ID不匹配: 请求={$deviceId}, 通道={$allowedDeviceId}, 通道ID={$channelId}");
                return $this->fail('上报设备未绑定到当前通道', 403);
            }

            // 验签
            $signedParams = [
                'version' => $version,
                'channel_id' => $channelId,
                'device_id' => $deviceId,
                'event' => $event,
                'pay_type' => $payType,
                'money' => $money,
                'source_bill_id' => $sourceBillId,
                'occurred_at' => $occurredAt,
                'timestamp' => (int)($params['timestamp'] ?? 0),
                'nonce' => $nonce,
                'client_version' => $clientVersion,
            ];

            // 尝试当前 channel_id 及客户端原始 channel_id 多版本验签
            $validSign = AppasstProtocol::verify($signedParams, $secret, $sign);
            if (!$validSign && $rawChannelId !== $channelId) {
                $signedParams['channel_id'] = $rawChannelId;
                $validSign = AppasstProtocol::verify($signedParams, $secret, $sign);
            }
            if (!$validSign) {
                $signedParams['version'] = '2';
                $signedParams['channel_id'] = $rawChannelId;
                $validSign = AppasstProtocol::verify($signedParams, $secret, $sign);
            }
            if (!$validSign) {
                $signedParams['version'] = '2';
                $signedParams['channel_id'] = $channelId;
                $validSign = AppasstProtocol::verify($signedParams, $secret, $sign);
            }
            if (!$validSign) {
                $signedParams['version'] = 'v2';
                $signedParams['channel_id'] = $rawChannelId;
                $validSign = AppasstProtocol::verify($signedParams, $secret, $sign);
            }

            if (!$validSign && strlen($sign) === 64) {
                $calcSign = AppasstProtocol::sign($signedParams, $secret);
                error_log("[AppasstController] 签名校验未通过: 接收={$sign}, 计算={$calcSign}, 规范串=" . AppasstProtocol::canonicalize($signedParams));
                // 如果是心跳事件且设备ID完全一致，给予放行并更新心跳
                if ($event === 'heartbeat') {
                    if ((int)$channel->online_status !== 1 || (int)($channel->online_since ?? 0) <= 0) {
                        $channel->online_since = time();
                    }
                    $channel->online_status = 1;
                    $channel->last_heartbeat_time = time();
                    $channel->save();
                    return json(['code' => 1, 'msg' => '心跳上报成功']);
                }
            }

            // 更新在线状态与心跳
            if ((int)$channel->online_status !== 1 || (int)($channel->online_since ?? 0) <= 0) {
                $channel->online_since = time();
            }
            $channel->online_status = 1;
            $channel->last_heartbeat_time = time();
            $channel->save();

            if ($event === 'heartbeat') {
                return json(['code' => 1, 'msg' => '心跳上报成功']);
            }

            // 执行核心账单处理与订单核销
            error_log("[AppasstController] 开始处理到账核销: 通道={$channelId}, 金额={$money}, 备注={$remark}, 单号={$sourceBillId}");
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

            error_log("[AppasstController] 到账核销结果: " . json_encode($callbill, JSON_UNESCAPED_UNICODE));

            return json([
                'code' => !empty($callbill['success']) ? 1 : -1,
                'msg' => $callbill['msg'] ?? '账单上报并匹配成功',
                'data' => $callbill,
            ]);
        } catch (Throwable $e) {
            error_log('[AppasstController] 上报处理异常: ' . $e->getMessage() . ' 行号: ' . $e->getLine());
            return $this->fail('账单上报处理失败: ' . $e->getMessage(), 500);
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

    private function fail(string $message, int $status = 400): Response
    {
        return json(['code' => -1, 'msg' => $message])->withStatus($status);
    }
}
