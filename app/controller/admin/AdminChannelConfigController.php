<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Channel;
use app\payment\PaymentManager;
use app\payment\RemovedPaymentDrivers;
use support\Authcode;

/**
 * 平台支付通道实例配置控制器
 */
final class AdminChannelConfigController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    /**
     * 获取或解密特定通道的加密配置参数
     */
    public function getChannelConfig(\support\Request $request): string
    {
        $channelId = (int)($request->get('id') ?? $request->post('id') ?? 0);
        $channel   = Channel::find($channelId);

        if (!$channel) {
            return json_encode(['code' => -1, 'msg' => '通道不存在'], JSON_UNESCAPED_UNICODE);
        }

        $rawConfig = json_decode($channel->config, true) ?: [];
        $decryptedConfig = [];
        $configured = [];
        foreach ($rawConfig as $k => $v) {
            if (is_string($v) && $this->isSensitiveConfigName((string)$k)) {
                $decryptedConfig[$k] = '';
                $configured[$k] = $v !== '';
            } else {
                $decryptedConfig[$k] = is_string($v) ? $this->authcode->decryptStored($v) : $v;
            }
        }

        return json_encode([
            'code' => 1,
            'data' => [
                'id'                  => $channel->id,
                'pay_category'        => $channel->pay_category,
                'title'               => $channel->title,
                'c_type'              => $channel->c_type,
                'remark'              => $channel->remark,
                'weight'              => $channel->weight,
                'single_min'          => $channel->single_min,
                'single_max'          => $channel->single_max,
                'day_max'             => $channel->day_max,
                'fallback_channel_id' => (int)($channel->fallback_channel_id ?? 0),
                'status'              => $channel->status,
                'config'              => $decryptedConfig,
                'configured'          => $configured,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 获取平台通道概览，列表接口不返回任何解密后的敏感配置。
     */
    public function listChannels(): string
    {
        $channels = Channel::where('merchant_id', 0)
            ->select([
                'id', 'pay_category', 'title', 'c_type', 'remark', 'weight',
                'single_min', 'single_max', 'day_max', 'online_status',
                'last_heartbeat_time', 'fallback_channel_id', 'status',
            ])
            ->orderByDesc('id')
            ->get()->toArray();

        // 核心驱动兜底清单，确保管理员后台 100% 渲染呈现
        $defaultDrivers = [
            [
                'id' => 3,
                'code' => 'qqpay_app_asst',
                'name' => 'QQ 钱包 App 助手',
                'pay_type' => 'qqpay',
                'enabled' => false,
                'weight' => 50,
                'configured' => false,
            ]
        ];

        if (!empty($channels)) {
            $formatted = array_map(function($c) {
                // ?? 只处理 null，用 ?: 同时处理 null 和空字符串
                $title   = (string)($c['title'] ?? '');
                $cType   = (string)($c['c_type'] ?? '');
                $payType = (string)($c['pay_category'] ?? 'alipay');
                return [
                    'id'                  => $c['id'],
                    'code'                => $cType ?: 'unknown',
                    'name'                => ($title ?: null) ?? ($cType ?: '未命名通道'),
                    'pay_type'            => $payType ?: 'alipay',
                    'c_type'              => $cType,
                    'remark'              => (string)($c['remark'] ?? ''),
                    'online_status'       => (int)($c['online_status'] ?? 0),
                    'enabled'             => (int)($c['status'] ?? 0) === 1,
                    'weight'              => (int)($c['weight'] ?? 100),
                    'fallback_channel_id' => (int)($c['fallback_channel_id'] ?? 0),
                    'configured'          => true,
                ];
            }, $channels);
            return json_encode(['code' => 1, 'data' => $formatted], JSON_UNESCAPED_UNICODE);
        }

        return json_encode(['code' => 1, 'data' => $defaultDrivers], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 保存/更新支付通道、权重与私钥配置 (Authcode 加密存储)
     */
    public function saveChannelConfig(\support\Request $request): string
    {
        $params    = $request->post();
        $channelId = (int)($params['id'] ?? 0);
        $cType     = trim((string)($params['c_type'] ?? ''));

        if (RemovedPaymentDrivers::contains($cType)) {
            return json_encode([
                'code' => -1,
                'msg' => '该支付驱动已永久移除，不能创建或修改通道',
            ], JSON_UNESCAPED_UNICODE);
        }

        $title     = trim((string)($params['title'] ?? ''));
        $remark    = trim((string)($params['remark'] ?? ''));
        $rawConfig = is_array($params['config'] ?? null) ? $params['config'] : [];

        foreach ($rawConfig as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key)
                || !is_scalar($value) || strlen((string)$value) > 20000) {
                return json_encode(['code' => -1, 'msg' => '通道配置字段格式或长度不合法'], JSON_UNESCAPED_UNICODE);
            }
            $rawConfig[$key] = trim((string)$value);
        }
        if (strlen((string)json_encode($rawConfig, JSON_UNESCAPED_UNICODE)) > 60000) {
            return json_encode(['code' => -1, 'msg' => '通道配置总长度超出限制'], JSON_UNESCAPED_UNICODE);
        }

        if (!preg_match('/^[a-z0-9_]{3,50}$/', $cType) || !PaymentManager::has($cType)) {
            return json_encode(['code' => -1, 'msg' => '支付驱动不存在或标识不合法'], JSON_UNESCAPED_UNICODE);
        }
        $driver = PaymentManager::make($cType);
        $driverMeta = $driver->getMeta();
        if ($channelId <= 0 && ($driverMeta['deprecated'] ?? false) === true) {
            return json_encode([
                'code' => -1,
                'msg' => '该支付驱动已停止新建，请安装并使用推荐替代插件',
            ], JSON_UNESCAPED_UNICODE);
        }
        $inputDefinitions = (array)($driverMeta['inputs'] ?? []);
        $allowedConfigNames = [];
        foreach ($inputDefinitions as $definition) {
            $inputName = trim((string)($definition['name'] ?? ''));
            if ($inputName !== '') {
                $allowedConfigNames[$inputName] = true;
            }
        }
        $rawConfig = array_intersect_key($rawConfig, $allowedConfigNames);
        foreach ($inputDefinitions as $definition) {
            $inputName = trim((string)($definition['name'] ?? ''));
            if ($inputName !== '' && !array_key_exists($inputName, $rawConfig) && isset($definition['default'])) {
                $rawConfig[$inputName] = trim((string)$definition['default']);
            }
        }
        if ($title === '' || mb_strlen($title) > 100 || mb_strlen($remark) > 255) {
            return json_encode(['code' => -1, 'msg' => '通道名称不能为空且名称、备注不能超出长度限制'], JSON_UNESCAPED_UNICODE);
        }

        $payCategory = trim((string)($params['pay_category'] ?? strstr($cType, '_', true)));
        if (!in_array($payCategory, ['wxpay', 'alipay', 'qqpay'], true)
            || !str_starts_with($cType, $payCategory . '_')) {
            return json_encode(['code' => -1, 'msg' => '支付分类与驱动不匹配'], JSON_UNESCAPED_UNICODE);
        }

        $weight           = (int)($params['weight'] ?? 50);
        $singleMin        = (float)($params['single_min'] ?? 0);
        $singleMax        = (float)($params['single_max'] ?? 0);
        $dayMax           = (float)($params['day_max'] ?? 0);
        $fallbackChannelId = max(0, (int)($params['fallback_channel_id'] ?? 0));
        if ($weight < 0 || $weight > 10000 || $singleMin < 0 || $singleMax < 0 || $dayMax < 0
            || ($singleMax > 0 && $singleMin > $singleMax)) {
            return json_encode(['code' => -1, 'msg' => '通道权重或金额限制不合法'], JSON_UNESCAPED_UNICODE);
        }
        // fallback_channel_id 合法性校验：必须为 0（无备用）或指向一条真实存在的平台通道
        if ($fallbackChannelId > 0) {
            $fallbackExists = Channel::where('id', $fallbackChannelId)->where('merchant_id', 0)->exists();
            if (!$fallbackExists) {
                return json_encode(['code' => -1, 'msg' => '备用通道 ID 不存在或不合法'], JSON_UNESCAPED_UNICODE);
            }
            // 防止通道将自身设为备用通道（循环引用）
            if ($fallbackChannelId === $channelId) {
                return json_encode(['code' => -1, 'msg' => '备用通道不能指向自身'], JSON_UNESCAPED_UNICODE);
            }
        }

        $channel = null;
        if ($channelId > 0) {
            $channel = Channel::where('id', $channelId)->where('merchant_id', 0)->first();
            if (!$channel) {
                return json_encode(['code' => -1, 'msg' => '平台通道不存在或无权修改'], JSON_UNESCAPED_UNICODE);
            }
            foreach (json_decode((string)$channel->config, true) ?: [] as $key => $storedValue) {
                if (!isset($allowedConfigNames[$key])
                    || !$this->isSensitiveConfigName((string)$key)
                    || !is_string($storedValue)) {
                    continue;
                }
                if (!array_key_exists($key, $rawConfig) || $rawConfig[$key] === '') {
                    $rawConfig[$key] = $this->authcode->decryptStored($storedValue);
                }
            }
        }

        $validated = $driver->upchannel([
            'id' => $channelId,
            'merchant_id' => 0,
            'pay_category' => $payCategory,
            'c_type' => $cType,
        ], $rawConfig);
        if (isset($validated['code']) && (int)$validated['code'] !== 1) {
            return json_encode([
                'code' => -1,
                'msg' => (string)($validated['msg'] ?? '通道配置校验失败'),
            ], JSON_UNESCAPED_UNICODE);
        }
        $rawConfig = $validated;

        // 使用 Authcode 算法加密存储通道私钥及敏感配置
        $encryptedConfig = [];
        foreach ($rawConfig as $k => $v) {
            $encryptedConfig[$k] = is_string($v) && $v !== ''
                ? $this->authcode->encrypt((string)$v)
                : $v;
        }

        $updateData = [
            'merchant_id'         => 0,
            'pay_category'        => $payCategory,
            'title'               => $title,
            'c_type'              => $cType,
            'remark'              => $remark,
            'config'              => json_encode($encryptedConfig, JSON_UNESCAPED_UNICODE),
            'weight'              => $weight,
            'single_min'          => $singleMin,
            'single_max'          => $singleMax,
            'day_max'             => $dayMax,
            'fallback_channel_id' => $fallbackChannelId,
            'status'              => (int)($params['status'] ?? 1) === 1 ? 1 : 0,
        ];

        if ($channelId > 0) {
            $channel->fill($updateData);
            $channel->save();
            $msg = '通道参数与加密配置修改成功';
        } else {
            $updateData += [
                'today_money' => 0.00,
                'today_count' => 0,
                'total_money' => 0.00,
                'online_status' => PaymentManager::requiresHeartbeat($cType) ? 0 : 1,
                'last_heartbeat_time' => 0,
            ];
            Channel::create($updateData);
            $msg = '添加新通道成功';
        }

        return json_encode(['code' => 1, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    }

    private function isSensitiveConfigName(string $name): bool
    {
        return preg_match('/(?:key|secret|token|password|private|cookie|cert)/i', $name) === 1;
    }
}
