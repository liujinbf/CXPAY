<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Channel;
use app\model\Merchant;
use app\payment\PaymentManager;
use app\payment\RemovedPaymentDrivers;
use app\service\OrderService;
use app\service\PluginLicenseService;
use Illuminate\Database\Capsule\Manager as DB;
use support\Authcode;
use support\Request;

/**
 * 商户自助支付通道管理 API。
 */
class MerchantChannelController
{
    protected Authcode $authcode;
    protected OrderService $orderService;

    public function __construct()
    {
        $this->authcode = new Authcode();
        $this->orderService = new OrderService();
    }

    public function list(Request $request)
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        $channels = Channel::where('merchant_id', $merchant->id)
            ->orderBy('id', 'desc')
            ->get()
            ->map(function (Channel $channel): array {
                $data = $channel->toArray();
                $rawConfig = json_decode((string)$channel->config, true) ?: [];
                $flatConfig = [];
                if (isset($rawConfig['data']) && is_array($rawConfig['data'])) {
                    foreach ($rawConfig['data'] as $k => $v) {
                        $flatConfig[$k] = $v;
                    }
                }
                foreach ($rawConfig as $k => $v) {
                    if ($k !== 'code' && $k !== 'msg' && $k !== 'data') {
                        $flatConfig[$k] = $v;
                    }
                }
                $config = [];
                $configured = [];
                foreach ($flatConfig as $key => $value) {
                    if (is_string($value)) {
                        if ($this->isSensitiveConfigName((string)$key)) {
                            $configured[$key] = $value !== '';
                            $config[$key] = '';
                        } else {
                            $config[$key] = $this->authcode->decryptStored($value);
                        }
                    } else {
                        $config[$key] = $value;
                    }
                }
                $data['config'] = $config;
                $data['configured'] = $configured;
                $data['qr_url'] = (string)($config['qr_url'] ?? $config['qr_code_url'] ?? '');
                $meta = [];
                try {
                    if (PaymentManager::has((string)$channel->c_type)) {
                        $meta = PaymentManager::make((string)$channel->c_type)->getMeta();
                    }
                } catch (\Throwable) {
                    // 插件暂时不可用时仍返回通道，方便商户停用或删除。
                }
                $data['supports_account_authorization'] = ($meta['supports_account_authorization'] ?? false) === true;
                $data['supports_account_capability_detection'] = ($meta['supports_account_capability_detection'] ?? false) === true;
                $data['authorization_label'] = (string)($meta['authorization_label'] ?? '扫码授权');
                
                $now = time();
                $onlineStatus = (int)($channel->online_status ?? 0);
                $onlineSince = (int)($channel->online_since ?? 0);
                // 如果 online_since 缺失但通道处于在线状态，稳定继承通道的创建时间作为在线起点
                if ($onlineStatus === 1 && $onlineSince <= 0) {
                    $channelCreatedAt = !empty($channel->created_at) ? strtotime((string)$channel->created_at) : (int)($channel->create_time ?? 0);
                    $onlineSince = $channelCreatedAt > 0 ? $channelCreatedAt : $now;
                    DB::table('cx_pay_channel')->where('id', $channel->id)->update(['online_since' => $onlineSince]);
                }

                $currentDuration = ($onlineStatus === 1 && $onlineSince > 0) ? max(1, $now - $onlineSince) : 0;
                $lastDuration = (int)($channel->last_online_duration ?? 0);
                if ($onlineStatus === 0 && $lastDuration <= 0 && $onlineSince > 0) {
                    $offlineSince = (int)($channel->offline_since ?? $now);
                    $lastDuration = max(1, $offlineSince - $onlineSince);
                }

                $data['online_status'] = $onlineStatus;
                $data['online_since'] = $onlineSince;
                $data['online_since_format'] = $onlineSince > 0 ? date('Y-m-d H:i:s', $onlineSince) : '';
                $data['online_duration'] = $currentDuration;
                $data['offline_since'] = (int)($channel->offline_since ?? 0);
                $data['last_online_duration'] = $lastDuration;
                return $data;
            })
            ->all();

        return json(['code' => 1, 'data' => $channels]);
    }

    public function save(Request $request)
    {
        $params = $request->post();
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        $id = (int)($params['id'] ?? 0);
        $payCategory = trim((string)($params['pay_category'] ?? ''));
        $cType = trim((string)($params['c_type'] ?? ''));

        if (RemovedPaymentDrivers::contains($cType)) {
            return json([
                'code' => -1,
                'msg' => '该支付驱动已永久移除，不能创建或修改通道',
            ]);
        }

        $title = trim((string)($params['title'] ?? ''));
        $remark = trim((string)($params['remark'] ?? ''));
        $status = (int)($params['status'] ?? 1) === 1 ? 1 : 0;

        if (!PaymentManager::has($cType) || !preg_match('/^[a-z0-9_]{3,50}$/', $cType)) {
            return json(['code' => -1, 'msg' => '支付驱动不存在']);
        }
        $meta = PaymentManager::getMeta($cType);
        $driverCategory = (string)($meta['category'] ?? $meta['pay_category'] ?? '');
        $normDriverCat = (str_starts_with($driverCategory, 'wx') || str_starts_with($driverCategory, 'wechat') || str_starts_with($cType, 'wx') || str_starts_with($cType, 'wechat')) ? 'wxpay' : (str_starts_with($driverCategory, 'ali') || str_starts_with($cType, 'ali') ? 'alipay' : (str_starts_with($driverCategory, 'qq') || str_starts_with($cType, 'qq') ? 'qqpay' : $driverCategory));
        $normPayCat = (str_starts_with($payCategory, 'wx') || str_starts_with($payCategory, 'wechat')) ? 'wxpay' : (str_starts_with($payCategory, 'ali') ? 'alipay' : (str_starts_with($payCategory, 'qq') ? 'qqpay' : $payCategory));
        if ($normDriverCat !== '' && $normDriverCat !== $normPayCat) {
            return json(['code' => -1, 'msg' => '支付驱动与支付分类不匹配']);
        }
        if (!PluginLicenseService::isChannelEntitled($cType)) {
            return json(['code' => -1, 'msg' => '该支付通道插件主站尚未购买开通，请联系平台管理员在插件市场开通后再使用！']);
        }
        if ($title === '' || mb_strlen($title) > 100 || mb_strlen($remark) > 255) {
            return json(['code' => -1, 'msg' => '通道名称不能为空且名称、备注不能超出长度限制']);
        }

        // 检查商户套餐状态：无套餐 (plan_id <= 0) 或套餐已过期不能添加/编辑通道
        $planId = (int)($merchant->plan_id ?? 0);
        $planExpire = (int)($merchant->plan_expire_time ?? 0);
        $isExpired = ($planExpire > 0 && $planExpire < time());
        if ($planId <= 0 || $isExpired) {
            return json([
                'code' => -100, // -100 专用表示需购买/订阅套餐
                'msg'  => '您当前尚未开通套餐或套餐已到期，请先前往「套餐订阅广场」领取免费试用套餐或购买套餐后再配置收款通道！'
            ]);
        }

        // 检查套餐允许的通道类型（allowed_channels 为空或包含 * / all 则不限制，向后兼容旧套餐数据）
        $currentPlan = \app\model\Plan::find($planId);
        if ($currentPlan) {
            $allowedChannels = array_values(array_filter(
                array_map('trim', explode(',', (string)($currentPlan->allowed_channels ?? '')))
            ));
            if ($allowedChannels !== []) {
                $isAllowed = in_array($cType, $allowedChannels, true)
                    || in_array($payCategory, $allowedChannels, true)
                    || in_array('*', $allowedChannels, true)
                    || in_array('all', $allowedChannels, true);
                if (!$isAllowed) {
                    return json([
                        'code' => -101, // -101 专用表示通道类型不在套餐范围内
                        'msg'  => '您当前套餐「' . $currentPlan->name . '」不包含此支付通道类型，请升级套餐或联系代理商开通。',
                    ]);
                }
            }
        }

        $channel = null;
        if ($id > 0) {
            $channel = Channel::where('id', $id)
                ->where('merchant_id', $merchant->id)
                ->first();
            if (!$channel) {
                return json(['code' => -1, 'msg' => '通道不存在或无权修改']);
            }
        }

        $driver = PaymentManager::make($cType);
        $meta = $driver->getMeta();
        if (!$channel && ($meta['deprecated'] ?? false) === true) {
            return json(['code' => -1, 'msg' => '该支付驱动已停止新建，请安装并使用推荐替代插件']);
        }
        $submittedConfig = is_array($params['config'] ?? null) ? $params['config'] : [];
        $existingConfig = [];
        if ($channel) {
            foreach (json_decode((string)$channel->config, true) ?: [] as $key => $value) {
                if (is_string($value)) {
                    $existingConfig[$key] = $this->authcode->decryptStored($value);
                } else {
                    $existingConfig[$key] = $value;
                }
            }
        }
        $legacyConfig = [
            'qr_url' => trim((string)($params['qr_url'] ?? '')),
            'qr_code_url' => trim((string)($params['qr_url'] ?? '')),
            'app_id' => trim((string)($params['app_id'] ?? '')),
            'merchant_private_key' => trim((string)($params['merchant_private_key'] ?? '')),
            'alipay_public_key' => trim((string)($params['alipay_public_key'] ?? '')),
            'alipay_pid' => trim((string)($params['alipay_pid'] ?? '')),
        ];

        $configData = [];
        foreach ((array)($meta['inputs'] ?? []) as $input) {
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $value = $submittedConfig[$name]
                ?? $params[$name]
                ?? $legacyConfig[$name]
                ?? $input['default']
                ?? '';
            if ($this->isSensitiveConfigName($name)
                && (string)$value === ''
                && isset($existingConfig[$name])
                && (string)$existingConfig[$name] !== '') {
                $value = $existingConfig[$name];
            }
            if (!is_scalar($value) || strlen((string)$value) > 2000000) {
                return json(['code' => -1, 'msg' => "驱动配置 {$name} 格式或长度不合法"]);
            }
            $configData[$name] = trim((string)$value);
        }
        if (strlen((string)json_encode($configData, JSON_UNESCAPED_UNICODE)) > 4000000) {
            return json(['code' => -1, 'msg' => '驱动配置总长度超出限制']);
        }

        $validated = $driver->upchannel([
            'id' => $id,
            'merchant_id' => (int)$merchant->id,
            'pay_category' => $payCategory,
            'c_type' => $cType,
            'status' => $status,
        ], $configData);
        if (isset($validated['code']) && (int)$validated['code'] !== 1) {
            return json(['code' => -1, 'msg' => (string)($validated['msg'] ?? '通道配置校验失败')]);
        }

        $actualConfig = (isset($validated['data']) && is_array($validated['data']))
            ? $validated['data']
            : (is_array($validated) ? $validated : $configData);

        $encryptedConfig = [];
        foreach ($actualConfig as $key => $value) {
            if ($key === 'code' || $key === 'msg') {
                continue;
            }
            $encryptedConfig[$key] = is_string($value) && $value !== ''
                ? $this->authcode->encrypt($value)
                : $value;
        }

        $dbData = [
            'merchant_id' => (int)$merchant->id,
            'pay_category' => $payCategory,
            'title' => $title,
            'c_type' => $cType,
            'remark' => $remark,
            'config' => json_encode($encryptedConfig, JSON_UNESCAPED_UNICODE),
            'status' => $status,
        ];

        if ($channel) {
            $channel->fill($dbData);
            if ((int)$channel->status === 1 && (int)$channel->online_status === 1 && (int)$channel->online_since <= 0) {
                $channelCreatedAt = !empty($channel->created_at) ? strtotime((string)$channel->created_at) : (int)($channel->create_time ?? 0);
                $channel->online_since = $channelCreatedAt > 0 ? $channelCreatedAt : time();
            }
            $channel->save();
            $channelId = (int)$channel->id;
        } else {
            $now = time();
            $dbData += [
                'today_money'          => 0.00,
                'today_count'          => 0,
                'total_money'          => 0.00,
                'online_status'        => 1, // 新建通道默认开启并保持在线
                'online_since'         => $now, // 默认开启在线时间统计
                'offline_since'        => 0,
                'last_online_duration' => 0,
                'last_heartbeat_time'  => $now,
            ];
            $channelId = (int)Channel::create($dbData)->id;
        }

        return json([
            'code' => 1,
            'msg' => $id > 0 ? '通道修改成功' : '通道新增成功',
            'id' => $channelId,
        ]);
    }

    public function toggle(Request $request)
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        $channel = Channel::where('id', (int)$request->post('id'))
            ->where('merchant_id', $merchant->id)
            ->first();
        if (!$channel) {
            return json(['code' => -1, 'msg' => '通道不存在或无权修改']);
        }
        $newStatus = (int)$request->post('status', 1) === 1 ? 1 : 0;
        $channel->status = $newStatus;
        
        $now = time();
        if ($newStatus === 1) {
            // 重新开启通道：仅当通道之前不在线或 online_since 缺失时才重置起始时间，
            // 避免反复点击「开启通道」导致在线时长归零（先用原始值判断，再赋 online_status）
            if ((int)$channel->online_status !== 1 || (int)($channel->online_since ?? 0) <= 0) {
                $channel->online_since = $now;
            }
            $channel->online_status = 1;

        } else {
            // 禁用通道：结算并记录掉线前在线时长
            if ((int)$channel->online_status === 1 && (int)$channel->online_since > 0) {
                $duration = max(0, $now - (int)$channel->online_since);
                if ($duration > 0) {
                    $channel->last_online_duration = $duration;
                }
            }
            $channel->offline_since = $now;
            $channel->online_status = 0;
        }
        
        $channel->save();

        return json(['code' => 1, 'msg' => '通道状态更新成功']);
    }

    public function delete(Request $request)
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        $deleted = Channel::where('id', (int)$request->post('id'))
            ->where('merchant_id', $merchant->id)
            ->delete();
        if (!$deleted) {
            return json(['code' => -1, 'msg' => '通道不存在或无权删除']);
        }

        return json(['code' => 1, 'msg' => '通道删除成功']);
    }

    public function drivers(Request $request)
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        // 读取当前套餐允许的通道类型白名单（为空则不限制）
        $allowedChannels = [];
        $planId          = (int)($merchant->plan_id ?? 0);
        $currentPlan     = null;
        if ($planId > 0) {
            $currentPlan = \app\model\Plan::find($planId);
            if ($currentPlan) {
                $allowedChannels = array_filter(
                    array_map('trim', explode(',', (string)($currentPlan->allowed_channels ?? '')))
                );
            }
        }

        // 计算套餐到期剩余天数（供前端展示预警）
        $planExpireTime = (int)($merchant->plan_expire_time ?? 0);
        $planDaysLeft   = null;
        if ($planExpireTime > 0) {
            $diff = $planExpireTime - time();
            $planDaysLeft = $diff > 0 ? (int)ceil($diff / 86400) : 0;
        }

        $grouped = ['wxpay' => [], 'alipay' => [], 'qqpay' => []];
        foreach (PaymentManager::getRegisteredDrivers() as $cType => $meta) {
            // 第一层过滤：校验主站当前是否已购买开通/拥有该插件有效授权（未开通的主站插件严格不向商户展示）
            if (!PluginLicenseService::isChannelEntitled((string)$cType)) {
                continue;
            }

            // 精确且智能地识别 pay_category
            $category = $meta['category'] ?? $meta['pay_category'] ?? '';
            if ($category === '' || !in_array($category, ['wxpay', 'alipay', 'qqpay'], true)) {
                if (str_starts_with($cType, 'wechat_') || str_starts_with($cType, 'wx_') || str_starts_with($cType, 'wxpay_')) {
                    $category = 'wxpay';
                } elseif (str_starts_with($cType, 'alipay_') || str_starts_with($cType, 'ali_')) {
                    $category = 'alipay';
                } elseif (str_starts_with($cType, 'qqpay_') || str_starts_with($cType, 'qq_')) {
                    $category = 'qqpay';
                } else {
                    $category = 'wxpay';
                }
            }

            // 若套餐设置了允许通道白名单，支持精确驱动名或大类匹配
            if ($allowedChannels !== []) {
                $isAllowed = in_array($cType, $allowedChannels, true)
                    || in_array($category, $allowedChannels, true)
                    || in_array('*', $allowedChannels, true)
                    || in_array('all', $allowedChannels, true);
                if (!$isAllowed) {
                    continue;
                }
            }

            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = [
                'c_type'                               => $cType,
                'name'                                 => (string)($meta['title'] ?? $meta['name'] ?? $cType),
                'description'                          => (string)($meta['description'] ?? ''),
                'inputs'                               => (array)($meta['inputs'] ?? []),
                'supports_account_authorization'       => ($meta['supports_account_authorization'] ?? false) === true,
                'supports_account_capability_detection'=> ($meta['supports_account_capability_detection'] ?? false) === true,
                'authorization_label'                  => (string)($meta['authorization_label'] ?? '扫码授权'),
                'platform_hosted'                      => ($meta['platform_hosted'] ?? false) === true,
                'platform_clerk_qrcode'                => (string)($meta['platform_clerk_qrcode'] ?? ''),
                'platform_clerk_name'                  => (string)($meta['platform_clerk_name'] ?? '平台收款助手'),
                'status'                               => 1,
            ];
        }

        return json([
            'code'              => 1,
            'data'              => $grouped,
            'allowed_channels'  => array_values($allowedChannels),
            // 套餐信息（供前端展示套餐到期预警提示）
            'plan_id'           => $planId,
            'plan_name'         => $currentPlan ? (string)$currentPlan->name : '',
            'plan_expire_time'  => $planExpireTime,
            'plan_days_left'    => $planDaysLeft, // null=永久/无限制，0=已过期，>0=剩余天数
        ]);
    }

    public function capabilities(Request $request)
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }
        $channel = Channel::where('id', (int)$request->post('id'))
            ->where('merchant_id', $merchant->id)
            ->first();
        if (!$channel || !PaymentManager::has((string)$channel->c_type)) {
            return json(['code' => -1, 'msg' => '通道不存在或支付插件不可用']);
        }
        $config = [];
        foreach (json_decode((string)$channel->config, true) ?: [] as $key => $value) {
            $config[$key] = is_string($value) ? $this->authcode->decryptStored($value) : $value;
        }
        try {
            $result = PaymentManager::detectAccountCapabilities((string)$channel->c_type, $config);
            $status = (string)($result['status'] ?? 'UNKNOWN');
            $result['receipt_not_opened'] = $status === 'RECEIPT_NOT_OPENED';
            $result['recommended_mode'] = match ($status) {
                'RECEIPT_AVAILABLE' => 'receipt',
                'RECEIPT_NOT_OPENED', 'BOOK_AVAILABLE' => 'book',
                default => null,
            };
            return json(['code' => 1, 'data' => $result]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '账号能力探测失败：' . $e->getMessage()]);
        }
    }

    public function startAuthorization(Request $request)
    {
        [$channel, $config, $error] = $this->authorizationChannel($request);
        if ($error) {
            return $error;
        }
        try {
            $config['channel_id'] = (int)$channel->id;
            $result = PaymentManager::startAccountAuthorization((string)$channel->c_type, $config);
            return json(['code' => 1, 'data' => $result]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '发起扫码授权失败：' . $e->getMessage()]);
        }
    }

    public function pollAuthorization(Request $request)
    {
        [$channel, $config, $error] = $this->authorizationChannel($request);
        if ($error) {
            return $error;
        }
        try {
            $result = PaymentManager::pollAccountAuthorization(
                (string)$channel->c_type,
                trim((string)$request->post('session_id')),
                $config
            );
            if (($result['status'] ?? '') === 'CONFIRMED') {
                $raw = json_decode((string)$channel->config, true) ?: [];

                // 方式一：account_id 单字段写回（扫码免挂等标准模式）
                $accountId = trim((string)($result['account_id'] ?? ''));
                if ($accountId !== '') {
                    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $accountId)) {
                        throw new \RuntimeException('云服务没有返回合法的账号 ID');
                    }
                    $raw['account_id'] = $this->authcode->encrypt($accountId);
                }

                // 方式二：config_patch 多字段写回（accountlog-monitor 等需同时写回多个配置的场景）
                $configPatch = is_array($result['config_patch'] ?? null) ? $result['config_patch'] : [];
                foreach ($configPatch as $patchKey => $patchValue) {
                    if (is_string($patchKey)
                        && preg_match('/^[A-Za-z0-9_]{1,64}$/', $patchKey)
                        && is_string($patchValue)
                        && $patchValue !== ''
                    ) {
                        $raw[$patchKey] = $this->authcode->encrypt($patchValue);
                    }
                }

                 if ($accountId === '' && $configPatch === []) {
                    throw new \RuntimeException('授权确认状态下必须返回账号 ID 或配置补丁');
                }

                $channel->config = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $channel->online_status = 1;
                $channel->online_since = time();
                $channel->save();
            }
            return json(['code' => 1, 'data' => $result]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '轮询扫码授权状态失败：' . $e->getMessage()]);
        }
    }

    /**
     * 在未保存通道前直接根据驱动类型发起扫码授权（用于添加通道弹窗中一键扫码提取Cookie）
     */
    public function startDriverAuth(Request $request)
    {
        $cType = trim((string)$request->post('c_type', ''));
        if ($cType === '' || !PaymentManager::has($cType)) {
            return json(['code' => -1, 'msg' => '指定的驱动类型不存在']);
        }
        try {
            $config = (array)$request->post('config', []);
            $result = PaymentManager::startAccountAuthorization($cType, $config);
            return json(['code' => 1, 'data' => $result]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '发起扫码登录提取失败：' . $e->getMessage()]);
        }
    }

    /**
     * 轮询驱动扫码授权状态（返回提取到的 Cookie 供前端直接回填到表单）
     */
    public function pollDriverAuth(Request $request)
    {
        $cType = trim((string)$request->post('c_type', ''));
        $sessionId = trim((string)$request->post('session_id', ''));
        if ($cType === '' || !PaymentManager::has($cType)) {
            return json(['code' => -1, 'msg' => '指定的驱动类型不存在']);
        }
        try {
            $config = (array)$request->post('config', []);
            $result = PaymentManager::pollAccountAuthorization($cType, $sessionId, $config);
            return json(['code' => 1, 'data' => $result]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '轮询扫码状态失败：' . $e->getMessage()]);
        }
    }

    /**
     * 一键生成并下载包含该商户该通道专属预装配置的 PC 监控客户端 Zip 包
     */
    public function downloadPresetClient(Request $request)
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        $id = (int)$request->get('id');
        $channel = DB::table('cx_pay_channel')->where('id', $id)->where('merchant_id', $merchant->id)->first();
        if (!$channel) {
            return json(['code' => 404, 'msg' => '未找到指定的支付通道'])->withStatus(404);
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
            $secret = bin2hex(random_bytes(16)); // 自动补齐 32 位高安全密钥
            $config['notify_secret'] = $this->authcode->encrypt($secret);
            DB::table('cx_pay_channel')->where('id', $id)->update([
                'config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]);
        }

        $payType = 'wxpay';
        if (str_contains((string)$channel->c_type, 'alipay')) $payType = 'alipay';
        elseif (str_contains((string)$channel->c_type, 'qqpay')) $payType = 'qqpay';

        $host = $request->header('host') ?: 'cs.fcwan.cn';
        $scheme = $request->header('x-forwarded-proto') ?: 'https';
        $serverUrl = "{$scheme}://{$host}";

        $presetData = [
            'server_url' => $serverUrl,
            'channel_id' => $channel->id,
            'device_id' => "PC_MCH_{$merchant->id}_CH{$channel->id}",
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
                return response()->download($zipPath, "CXPayMonitor-Channel-{$channel->id}-Preset.zip");
            }
        }

        // 兜底返回 json 配置供直接查看
        return json(['code' => 1, 'msg' => '专属配置生成成功', 'data' => $presetData]);
    }

    /**
     * 发起通道连通性测试（创建测试订单并调起通道出码）
     */
    public function test(Request $request): \support\Response
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }
        $channelId = (int)$request->post('channel_id', 0);
        $money = round((float)$request->post('money', 0.01), 2);
        if ($money <= 0) {
            $money = 0.01;
        }

        $channel = Channel::where('id', $channelId)
            ->where('merchant_id', $merchant->id)
            ->first();
        if (!$channel) {
            return json(['code' => -1, 'msg' => '未找到该收款通道']);
        }
        if (!PaymentManager::has((string)$channel->c_type)) {
            return json(['code' => -1, 'msg' => '该通道的底层支付驱动未安装或不可用']);
        }

        $config = [];
        $rawConfig = json_decode((string)$channel->config, true) ?: [];
        if (isset($rawConfig['data']) && is_array($rawConfig['data'])) {
            foreach ($rawConfig['data'] as $k => $v) {
                $config[$k] = is_string($v) ? $this->authcode->decryptStored($v) : $v;
            }
        }
        foreach ($rawConfig as $key => $value) {
            if ($key !== 'code' && $key !== 'msg' && $key !== 'data') {
                $config[$key] = is_string($value) ? $this->authcode->decryptStored($value) : $value;
            }
        }

        $tradeNo = 'TEST_' . date('YmdHis') . '_' . mt_rand(1000, 9999);
        $outTradeNo = 'OUT_' . date('YmdHis') . '_' . mt_rand(100, 999);

        $now = time();
        $isHttps = strtolower((string)$request->header('x-forwarded-proto', '')) === 'https'
            || (string)$request->header('https', '') === 'on'
            || (int)$request->header('server-port', 0) === 443;
        $scheme = $isHttps ? 'https://' : 'http://';
        $notifyUrl = $scheme . $request->host() . '/notify/' . $channel->c_type;

        // 创建临时测试订单记录（严格对齐 cx_order 数据库表结构）
        \app\model\Order::create([
            'merchant_id'      => (int)$merchant->id,
            'out_trade_no'     => $outTradeNo,
            'trade_no'         => $tradeNo,
            'channel_id'       => (int)$channel->id,
            'pay_type'         => (string)($channel->pay_category ?: 'alipay'),
            'business_type'    => 'payment',
            'fee_amount'       => 0.00,
            'fee_status'       => 0,
            'amount'           => number_format($money, 2, '.', ''),
            'price'            => number_format($money, 2, '.', ''),
            'subject'          => '通道连通性测试 - ' . ($channel->title ?: $channel->c_type),
            'notify_url'       => $notifyUrl,
            'return_url'       => '',
            'pay_url'          => '',
            'pay_mode'         => 'qrcode',
            'pay_init_status'  => 1,
            'pay_init_time'    => $now,
            'channel_trade_no' => '',
            'status'           => 0,
            'source_bill_id'   => '',
            'notify_status'    => 0,
            'create_time'      => $now,
            'expire_time'      => $now + 600,
            'pay_time'         => 0,
        ]);

        $driver = PaymentManager::make((string)$channel->c_type);
        try {
            $payParams = [
                'trade_no'     => $tradeNo,
                'out_trade_no' => $outTradeNo,
                'name'         => '通道连通性测试',
                'money'        => number_format($money, 2, '.', ''),
                'type'         => (string)($channel->pay_category ?: 'alipay'),
                'notify_url'   => $notifyUrl,
            ];
            $res = $driver->pay($payParams, $config);
            return json([
                'code' => 1,
                'msg'  => 'ok',
                'data' => [
                    'trade_no'      => $tradeNo,
                    'channel_id'    => $channel->id,
                    'channel_title' => $channel->title ?: $channel->c_type,
                    'money'         => number_format($money, 2, '.', ''),
                    'pay_type'      => $res['type'] ?? 'qrcode',
                    'pay_url'       => $res['pay_url'] ?? $res['qrcode'] ?? '',
                    'expire_time'   => 600,
                ],
            ]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '通道出码失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 查单测试状态
     */
    public function testStatus(Request $request): \support\Response
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }
        $tradeNo = trim((string)$request->post('trade_no', ''));
        if ($tradeNo === '') {
            return json(['code' => -1, 'msg' => '订单号不能为空']);
        }
        $order = \app\model\Order::where('trade_no', $tradeNo)
            ->where('merchant_id', $merchant->id)
            ->first();
        if (!$order) {
            return json(['code' => 1, 'data' => ['paid' => false]]);
        }

        // 若订单尚未核销（status !== 1），主动向驱动发起查单兜底
        if ((int)$order->status !== 1 && $order->channel_id > 0) {
            $channel = Channel::find($order->channel_id);
            if ($channel && PaymentManager::has((string)$channel->c_type)) {
                $rawConfig = json_decode((string)$channel->config, true) ?: [];
                $cfg = [];
                if (isset($rawConfig['data']) && is_array($rawConfig['data'])) {
                    foreach ($rawConfig['data'] as $k => $v) {
                        $cfg[$k] = is_string($v) ? $this->authcode->decryptStored($v) : $v;
                    }
                }
                foreach ($rawConfig as $k => $v) {
                    if ($k !== 'code' && $k !== 'msg' && $k !== 'data') {
                        $cfg[$k] = is_string($v) ? $this->authcode->decryptStored($v) : $v;
                    }
                }
                try {
                    $qRes = PaymentManager::make((string)$channel->c_type)->query((string)$order->trade_no, $cfg);
                    if (!empty($qRes['paid'])) {
                        $this->orderService->markAsPaid(
                            (string)$order->trade_no,
                            (string)($qRes['channel_trade_no'] ?? $qRes['trade_no'] ?? ''),
                            (float)($qRes['amount'] ?? $order->real_money ?? $order->money),
                            (int)$channel->id,
                            true
                        );
                        $order->refresh();
                    }
                } catch (\Throwable) {
                    // 忽略主动查单中的偶发异常
                }
            }
        }

        return json([
            'code' => 1,
            'data' => [
                'paid'     => (int)$order->status === 1,
                'pay_time' => $order->end_time ?: $order->pay_time,
            ],
        ]);
    }

    /** @return array{0:?Channel,1:array,2:mixed} */
    private function authorizationChannel(Request $request): array
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return [null, [], json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401)];
        }
        $channel = Channel::where('id', (int)$request->post('id'))
            ->where('merchant_id', $merchant->id)
            ->first();
        if (!$channel || !PaymentManager::has((string)$channel->c_type)) {
            return [null, [], json(['code' => -1, 'msg' => '通道不存在或支付插件不可用'])];
        }
        $config = [];
        foreach (json_decode((string)$channel->config, true) ?: [] as $key => $value) {
            $config[$key] = is_string($value) ? $this->authcode->decryptStored($value) : $value;
        }
        return [$channel, $config, null];
    }

    private function currentMerchant(Request $request): ?Merchant
    {
        $merchant = $request->context['merchant'] ?? null;
        return $merchant instanceof Merchant ? $merchant : null;
    }

    public function appasstSyncPair(Request $request)
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        $deviceId = trim((string)$request->post('device_id', ''));
        $secret = trim((string)$request->post('notify_secret', ''));
        $wxId = (int)$request->post('wx_channel_id', 0);
        $aliId = (int)$request->post('ali_channel_id', 0);

        if (strlen($secret) < 32 || $deviceId === '') {
            return json(['code' => -1, 'msg' => '配对参数不合法']);
        }

        $ids = array_filter([$wxId, $aliId], fn($id) => $id > 0);
        foreach ($ids as $cid) {
            $channel = Channel::where('id', $cid)->where('merchant_id', $merchant->id)->first();
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
