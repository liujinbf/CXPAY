<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Channel;
use app\model\Merchant;
use app\payment\PaymentManager;
use support\Authcode;
use support\Request;

/**
 * 商户自助支付通道管理 API。
 */
class MerchantChannelController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
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
                $config = json_decode((string)$channel->config, true) ?: [];
                $configured = [];
                foreach ($config as $key => $value) {
                    if (is_string($value)) {
                        if ($this->isSensitiveConfigName((string)$key)) {
                            $configured[$key] = $value !== '';
                            $config[$key] = '';
                        } else {
                            $config[$key] = $this->authcode->decryptStored($value);
                        }
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
        $title = trim((string)($params['title'] ?? ''));
        $remark = trim((string)($params['remark'] ?? ''));
        $status = (int)($params['status'] ?? 1) === 1 ? 1 : 0;

        if (!in_array($payCategory, ['wxpay', 'alipay', 'qqpay'], true)) {
            return json(['code' => -1, 'msg' => '支付分类不合法']);
        }
        if (!preg_match('/^[a-z0-9_]{3,50}$/', $cType)
            || !str_starts_with($cType, $payCategory . '_')
            || !PaymentManager::has($cType)) {
            return json(['code' => -1, 'msg' => '支付驱动不存在或与支付分类不匹配']);
        }
        if ($title === '' || mb_strlen($title) > 100 || mb_strlen($remark) > 255) {
            return json(['code' => -1, 'msg' => '通道名称不能为空且名称、备注不能超出长度限制']);
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
            if (!is_scalar($value) || strlen((string)$value) > 20000) {
                return json(['code' => -1, 'msg' => "驱动配置 {$name} 格式或长度不合法"]);
            }
            $configData[$name] = trim((string)$value);
        }
        if (strlen((string)json_encode($configData, JSON_UNESCAPED_UNICODE)) > 60000) {
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

        $encryptedConfig = [];
        foreach ($validated as $key => $value) {
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
            $channel->save();
            $channelId = (int)$channel->id;
        } else {
            $dbData += [
                'today_money' => 0.00,
                'today_count' => 0,
                'total_money' => 0.00,
                'online_status' => PaymentManager::requiresHeartbeat($cType) ? 0 : 1,
                'last_heartbeat_time' => 0,
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
        $channel->status = (int)$request->post('status', 1) === 1 ? 1 : 0;
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
        if (!$this->currentMerchant($request)) {
            return json(['code' => 401, 'msg' => '商户身份无效'])->withStatus(401);
        }

        $grouped = ['wxpay' => [], 'alipay' => [], 'qqpay' => [], 'other' => []];
        foreach (PaymentManager::getRegisteredDrivers() as $cType => $meta) {
            $category = str_starts_with($cType, 'wxpay_') ? 'wxpay'
                : (str_starts_with($cType, 'alipay_') ? 'alipay'
                    : (str_starts_with($cType, 'qqpay_') ? 'qqpay' : 'other'));
            $grouped[$category][] = [
                'c_type' => $cType,
                'name' => (string)($meta['title'] ?? $cType),
                'description' => (string)($meta['description'] ?? ''),
                'inputs' => (array)($meta['inputs'] ?? []),
                'supports_account_authorization' => ($meta['supports_account_authorization'] ?? false) === true,
                'supports_account_capability_detection' => ($meta['supports_account_capability_detection'] ?? false) === true,
                'authorization_label' => (string)($meta['authorization_label'] ?? '扫码授权'),
                'status' => 1,
            ];
        }

        return json(['code' => 1, 'data' => $grouped]);
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
                $accountId = trim((string)($result['account_id'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $accountId)) {
                    throw new \RuntimeException('云服务没有返回合法的账号 ID');
                }
                $raw = json_decode((string)$channel->config, true) ?: [];
                $raw['account_id'] = $this->authcode->encrypt($accountId);
                $channel->config = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $channel->save();
            }
            return json(['code' => 1, 'data' => $result]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '查询扫码授权状态失败：' . $e->getMessage()]);
        }
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
        if (!$channel || (int)$channel->status !== 0 || !PaymentManager::has((string)$channel->c_type)) {
            return [null, [], json(['code' => -1, 'msg' => '请先保存并停用需要授权的支付通道'])];
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

    private function isSensitiveConfigName(string $name): bool
    {
        return preg_match('/(?:key|secret|token|password|private|cookie|cert)/i', $name) === 1;
    }
}
