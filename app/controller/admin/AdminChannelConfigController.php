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
     * 获取管理后台用于新建通道时可用的已安装底层支付驱动列表（按大类分组）
     */
    public function drivers(\support\Request $request): string
    {
        PaymentManager::flush();
        $registered = PaymentManager::getRegisteredDrivers();
        $grouped = [];

        foreach ($registered as $cType => $meta) {
            if (RemovedPaymentDrivers::contains($cType)) {
                continue;
            }
            if (!\app\service\PluginLicenseService::isChannelEntitled((string)$cType)) {
                continue;
            }
            $category = $meta['category'] ?? $meta['pay_category'] ?? '';
            if ($category === '') {
                if (str_starts_with($cType, 'wechat_') || str_starts_with($cType, 'wx_') || str_starts_with($cType, 'wxpay_')) {
                    $category = 'wxpay';
                } elseif (str_starts_with($cType, 'alipay_') || str_starts_with($cType, 'ali_')) {
                    $category = 'alipay';
                } elseif (str_starts_with($cType, 'qqpay_') || str_starts_with($cType, 'qq_')) {
                    $category = 'qqpay';
                } elseif (str_starts_with($cType, 'usdt_')) {
                    $category = 'usdt';
                } else {
                    $category = 'other';
                }
            }

            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }

            $driverData = [
                'c_type'                         => $cType,
                'name'                           => (string)($meta['title'] ?? $meta['name'] ?? $cType),
                'pay_category'                   => $category,
                'badge'                          => (string)($meta['badge'] ?? ''),
                'supports_account_authorization' => ($meta['supports_account_authorization'] ?? false) === true,
                'authorization_label'            => (string)($meta['authorization_label'] ?? '扫码授权'),
                'description'                    => (string)($meta['description'] ?? ''),
                'inputs'                         => $meta['inputs'] ?? [],
                'has_oauth'                      => ($cType === 'alipay_face_pay' || ($meta['supports_account_authorization'] ?? false) === true),
                'platform_clerk_qrcode'          => (string)($meta['platform_clerk_qrcode'] ?? ''),
                'platform_clerk_name'            => (string)($meta['platform_clerk_name'] ?? '平台官方收款店员'),
            ];

            $grouped[$category][] = $driverData;
        }

        return json_encode([
            'code' => 1,
            'data' => $grouped,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 获取管理后台用于新建通道时可用的已安装底层支付驱动列表
     */
    public function getDriverList(\support\Request $request): string
    {
        return $this->drivers($request);
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
        // 自动清理/忽略已永久废弃驱动的通道
        $channels = Channel::where('merchant_id', 0)
            ->select([
                'id', 'pay_category', 'title', 'c_type', 'remark', 'weight',
                'single_min', 'single_max', 'day_max', 'online_status',
                'online_since', 'offline_since', 'last_online_duration',
                'last_heartbeat_time', 'fallback_channel_id', 'status',
            ])
            ->orderByDesc('id')
            ->get()->toArray();

        $formatted = [];
        foreach ($channels as $c) {
            $cType = (string)($c['c_type'] ?? '');
            if ($cType !== '' && RemovedPaymentDrivers::contains($cType)) {
                continue;
            }

            $title   = (string)($c['title'] ?? '');
            $payType = (string)($c['pay_category'] ?? 'alipay');
            $onlineStatus = (int)($c['online_status'] ?? 0);
            $onlineSince  = (int)($c['online_since'] ?? 0);
            if ($onlineStatus === 1 && $onlineSince <= 0) {
                $onlineSince = time();
                Channel::where('id', $c['id'])->update(['online_since' => $onlineSince]);
            }
            $formatted[] = [
                'id'                  => $c['id'],
                'code'                => $cType ?: 'unknown',
                'name'                => ($title ?: null) ?? ($cType ?: '未命名通道'),
                'pay_type'            => $payType ?: 'alipay',
                'c_type'              => $cType,
                'remark'              => (string)($c['remark'] ?? ''),
                'online_status'       => $onlineStatus,
                'online_since'        => $onlineSince,
                'offline_since'       => (int)($c['offline_since'] ?? 0),
                'last_online_duration'=> (int)($c['last_online_duration'] ?? 0),
                'enabled'             => (int)($c['status'] ?? 0) === 1,
                'weight'              => (int)($c['weight'] ?? 100),
                'fallback_channel_id' => (int)($c['fallback_channel_id'] ?? 0),
                'configured'          => true,
            ];
        }

        return json_encode(['code' => 1, 'data' => $formatted], JSON_UNESCAPED_UNICODE);
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

        $payCategory = trim((string)($params['pay_category'] ?? ''));
        if ($payCategory === '' || !in_array($payCategory, ['alipay', 'wxpay', 'qqpay', 'crypto', 'bank'], true)) {
            if (str_starts_with($cType, 'ali')) $payCategory = 'alipay';
            elseif (str_starts_with($cType, 'wx') || str_starts_with($cType, 'wechat')) $payCategory = 'wxpay';
            elseif (str_starts_with($cType, 'qq')) $payCategory = 'qqpay';
            elseif (str_contains($cType, 'usdt')) $payCategory = 'crypto';
            else $payCategory = 'alipay';
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
            if ((int)$channel->online_status === 1 && (int)($channel->online_since ?? 0) <= 0) {
                $channel->online_since = time();
            }
            $channel->save();
            $msg = '通道参数与加密配置修改成功';
        } else {
            $now = time();
            $updateData += [
                'today_money'          => 0.00,
                'today_count'          => 0,
                'total_money'          => 0.00,
                'online_status'        => 1, // 默认开启在线
                'online_since'         => $now, // 默认开启在线时长统计
                'offline_since'        => 0,
                'last_online_duration' => 0,
                'last_heartbeat_time'  => $now,
            ];
            Channel::create($updateData);
            $msg = '添加新通道成功';
        }

        return json_encode(['code' => 1, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 管理后台一键生成并下载通道专属预装监控端 Zip
     */
    public function downloadPresetClient(\support\Request $request)
    {
        try {
            $id = (int)$request->get('id');
            $channel = Channel::find($id);
            if (!$channel) {
                return json(['code' => 404, 'msg' => '通道不存在'])->withStatus(404);
            }

            $config = json_decode((string)$channel->config, true) ?: [];
            $secret = '';
            foreach ($config as $k => $v) {
                if ($k === 'notify_secret' || $k === 'secret' || $k === 'app_secret') {
                    $secret = is_string($v) ? $this->authcode->decryptStored($v) : (string)$v;
                    break;
                }
            }
            if ($secret === '' || strlen($secret) < 32) {
                $secret = bin2hex(random_bytes(16));
                $config['notify_secret'] = $this->authcode->encrypt($secret);
                $channel->config = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $channel->save();
            }

            $payType = 'wxpay';
            if (str_contains((string)$channel->c_type, 'alipay')) $payType = 'alipay';
            elseif (str_contains((string)$channel->c_type, 'qqpay')) $payType = 'qqpay';

            $host = $request->header('host') ?: 'cs.fcwan.cn';
            $scheme = $request->header('x-forwarded-proto') ?: 'https';
            $serverUrl = "{$scheme}://{$host}";

            $presetData = [
                'server_url' => $serverUrl,
                'channel_id' => (int)$channel->id,
                'device_id' => "PC_ADMIN_CH{$channel->id}",
                'pay_type' => $payType,
                'notify_secret' => $secret,
                'capture_mode' => 'wechat_ui',
                'poll_seconds' => 5
            ];

            $tmpDir = sys_get_temp_dir() . '/cxpay_presets';
            if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

            $zipPath = $tmpDir . "/CXPayMonitor-Channel-{$channel->id}.zip";
            $baseZip = public_path() . '/downloads/CXPayMonitor-v1.3.5-Release.zip';

            if (class_exists(\ZipArchive::class) && file_exists($baseZip)) {
                copy($baseZip, $zipPath);
                $zip = new \ZipArchive();
                if ($zip->open($zipPath) === true) {
                    $zip->addFromString('config.json', json_encode($presetData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $zip->close();
                    return response('')->download($zipPath, "CXPayMonitor-Channel-{$channel->id}-Preset.zip");
                }
            }

            return json(['code' => 1, 'msg' => '专属配置生成成功', 'data' => $presetData]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '生成专属包异常：' . $e->getMessage()]);
        }
    }

    /**
     * 获取平台统一托管店员配置
     */
    public function getPlatformClerkConfig(\support\Request $request)
    {
        $clerkQrcode = '';
        $clerkName = '平台官方收款店员';

        try {
            $file = runtime_path() . '/wechat_dy_bill_clerk.json';
            if (file_exists($file)) {
                $data = json_decode((string)file_get_contents($file), true) ?: [];
                $clerkQrcode = (string)($data['clerk_qrcode'] ?? '');
                $clerkName = (string)($data['clerk_name'] ?? '平台官方收款店员');
            }

            if (empty($clerkQrcode)) {
                $channel = Channel::where('merchant_id', 0)->where('c_type', 'wechat_dy_bill')->first();
                if ($channel && !empty($channel->config)) {
                    $rawConfig = json_decode((string)$channel->config, true) ?: [];
                    $clerkQrcode = (string)($rawConfig['clerk_qrcode'] ?? '');
                    $clerkName = (string)($rawConfig['clerk_name'] ?? '平台官方收款店员');
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        if (empty($clerkName)) {
            $clerkName = '平台官方收款店员';
        }

        return json([
            'code' => 1,
            'data' => [
                'clerk_qrcode' => $clerkQrcode,
                'clerk_name'   => $clerkName,
            ],
        ]);
    }

    /**
     * 保存平台统一托管店员配置
     */
    public function savePlatformClerkConfig(\support\Request $request)
    {
        $clerkQrcode = trim((string)$request->post('clerk_qrcode', ''));
        $clerkName   = trim((string)$request->post('clerk_name', '平台官方收款店员'));
        if (empty($clerkName)) {
            $clerkName = '平台官方收款店员';
        }

        try {
            $file = runtime_path() . '/wechat_dy_bill_clerk.json';
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            file_put_contents($file, json_encode([
                'clerk_qrcode' => $clerkQrcode,
                'clerk_name'   => $clerkName,
                'updated_at'   => time(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $channel = Channel::where('merchant_id', 0)->where('c_type', 'wechat_dy_bill')->first();
            if ($channel) {
                $rawConfig = json_decode((string)$channel->config, true) ?: [];
                $rawConfig['clerk_qrcode'] = $clerkQrcode;
                $rawConfig['clerk_name']   = $clerkName;
                $channel->config = json_encode($rawConfig, JSON_UNESCAPED_UNICODE);
                $channel->save();
            }

            return json(['code' => 1, 'msg' => '平台官方统一店员配置保存成功！已全局同步至所有商户']);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '保存失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取平台支付宝 ISV 扫码代授权主应用配置
     */
    public function getIsvConfig(\support\Request $request)
    {
        try {
            $configs = \support\Db::table('cx_config')->whereIn('name', [
                'alipay_isv_app_id',
                'alipay_isv_private_key',
                'alipay_isv_public_key',
            ])->pluck('value', 'name')->toArray();

            $appId = (string)($configs['alipay_isv_app_id'] ?? env('ALIPAY_ISV_APP_ID', ''));
            $privateKey = (string)($configs['alipay_isv_private_key'] ?? env('ALIPAY_ISV_PRIVATE_KEY', ''));
            $publicKey = (string)($configs['alipay_isv_public_key'] ?? env('ALIPAY_ISV_PUBLIC_KEY', ''));

            $host = (string)$request->header('host', '');
            $scheme = (string)$request->header('x-forwarded-proto', 'https');
            $callbackUrl = $host ? "{$scheme}://{$host}/api/alipay/auth/callback" : '/api/alipay/auth/callback';

            return json([
                'code' => 1,
                'data' => [
                    'app_id'       => $appId,
                    'private_key'  => $privateKey,
                    'public_key'   => $publicKey,
                    'configured'   => !empty($appId) && !empty($privateKey),
                    'callback_url' => $callbackUrl,
                ],
            ]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '读取 ISV 配置失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 保存平台支付宝 ISV 扫码代授权主应用配置
     */
    public function saveIsvConfig(\support\Request $request)
    {
        $appId = trim((string)$request->post('app_id', ''));
        $privateKey = trim((string)$request->post('private_key', ''));
        $publicKey = trim((string)$request->post('public_key', ''));

        try {
            \support\Db::table('cx_config')->updateOrInsert(
                ['name' => 'alipay_isv_app_id'],
                ['value' => $appId, 'title' => '支付宝ISV第三方应用AppID']
            );
            if ($privateKey !== '') {
                \support\Db::table('cx_config')->updateOrInsert(
                    ['name' => 'alipay_isv_private_key'],
                    ['value' => $privateKey, 'title' => '支付宝ISV第三方应用私钥']
                );
            }
            if ($publicKey !== '') {
                \support\Db::table('cx_config')->updateOrInsert(
                    ['name' => 'alipay_isv_public_key'],
                    ['value' => $publicKey, 'title' => '支付宝ISV第三方应用公钥']
                );
            }

            return json(['code' => 1, 'msg' => '🎉 支付宝 ISV 扫码代授权主应用配置保存成功！']);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '保存失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 扫码免配置配对时实时将生成的 device_id 与 notify_secret 写入通道
     */
    public function appasstSyncPair(\support\Request $request)
    {
        $deviceId = trim((string)$request->post('device_id', ''));
        $secret = trim((string)$request->post('notify_secret', ''));
        $wxId = (int)$request->post('wx_channel_id', 0);
        $aliId = (int)$request->post('ali_channel_id', 0);

        if (strlen($secret) < 32 || $deviceId === '') {
            return json(['code' => -1, 'msg' => '配对参数不合法']);
        }

        $ids = array_filter([$wxId, $aliId], fn($id) => $id > 0);
        foreach ($ids as $cid) {
            $channel = Channel::find($cid);
            if ($channel) {
                $rawConfig = json_decode((string)$channel->config, true) ?: [];
                $decrypted = [];
                foreach ($rawConfig as $k => $v) {
                    $decrypted[$k] = is_string($v) ? $this->authcode->decryptStored($v) : $v;
                }
                $decrypted['device_id'] = $deviceId;
                $decrypted['notify_secret'] = $secret;

                $encrypted = [];
                foreach ($decrypted as $k => $v) {
                    $encrypted[$k] = is_string($v) ? $this->authcode->encrypt($v) : $v;
                }
                $channel->config = json_encode($encrypted, JSON_UNESCAPED_UNICODE);
                $channel->save();
            }
        }

        return json(['code' => 1, 'msg' => '配对密钥已实时同步至通道配置']);
    }

    private function isSensitiveConfigName(string $name): bool
    {
        return preg_match('/(?:key|secret|token|password|private|cookie|cert)/i', $name) === 1;
    }
}
