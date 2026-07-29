<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Merchant;
use app\model\Order;
use support\Authcode;
use support\Sign;
use support\LoginRateLimiter;
use Exception;

/**
 * 商户后台与商户 API 接口控制器 (包含商户资料、密钥重置、充值与自测下单)
 */
class MerchantApiController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    /**
     * 商户登录接口
     */
    public function login(\support\Request $request): string
    {
        $params   = $request->post();
        $account  = trim((string)($params['account'] ?? ''));
        $password = trim((string)($params['password'] ?? ''));

        if (empty($account) || empty($password)) {
            return json_encode(['code' => -1, 'msg' => '商户 PID 与登录密码不能为空'], JSON_UNESCAPED_UNICODE);
        }

        $rateLimitId = $request->getRemoteIp() . '|' . strtolower($account);
        if (LoginRateLimiter::tooManyAttempts('merchant', $rateLimitId)) {
            return json_encode(['code' => -1, 'msg' => '登录失败次数过多，请5分钟后重试'], JSON_UNESCAPED_UNICODE);
        }

        $merchant = Merchant::where(function($q) use ($account) {
            $q->where('pid', $account)->orWhere('id', $account);
        })->first();

        if ($merchant && (int)$merchant->status === 1) {
            $passwordHash = (string)($merchant->password_hash ?? '');
            $verified = $passwordHash !== ''
                ? password_verify($password, $passwordHash)
                : hash_equals((string)$merchant->key, $password);
            if ($verified) {
                // 旧数据库首次成功登录后，将原 API 密钥迁移成独立的登录密码哈希。
                if ($passwordHash === '' || password_needs_rehash($passwordHash, PASSWORD_BCRYPT, ['cost' => 12])) {
                    $merchant->password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $merchant->save();
                }
                $session = $request->session();
                $session->set('merchant_id', $merchant->id);
                $request->sessionRegenerateId(true);
                LoginRateLimiter::clear('merchant', $rateLimitId);

                return json_encode([
                    'code' => 1,
                    'msg'  => '商户登录成功！正在跳转控制台...',
                    'data' => [
                        'pid'  => $merchant->pid ?? $merchant->id,
                        'name' => $merchant->name
                    ]
                ], JSON_UNESCAPED_UNICODE);
            }
        }

        return json_encode(['code' => -1, 'msg' => '商户 PID 或登录密码错误'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 商户注销退出
     */
    public function logout(\support\Request $request): string
    {
        $request->session()->forget('merchant_id');
        $request->sessionRegenerateId(true);
        return json_encode(['code' => 1, 'msg' => '商户已成功退出登录'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 获取当前商户个人资料与对账概览
     */
    public function getProfile(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);

        if (!$merchant) {
            return json_encode(['code' => -1, 'msg' => '未找到商户信息'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'code' => 1,
            'data' => [
                'pid'           => $merchant->pid,
                'name'          => $merchant->name,
                'key_configured'=> trim((string)$merchant->key) !== '',
                'money'         => number_format((float)($merchant->money ?? 0), 2, '.', ''),
                'rate'          => (float)($merchant->rate ?? 0.02),
                'packvip_time'  => $merchant->packvip_time ? date('Y-m-d H:i:s', $merchant->packvip_time) : '未开通',
                'status'        => $merchant->status,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 重置商户对接密钥 (KEY)
     */
    public function resetKey(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);

        if (!$merchant) {
            return json_encode(['code' => -1, 'msg' => '商户不存在'], JSON_UNESCAPED_UNICODE);
        }

        $rateLimitId = $request->getRemoteIp() . '|' . (string)$merchant->id;
        if (LoginRateLimiter::tooManyAttempts('merchant_key_reset', $rateLimitId, 5, 300)) {
            return json_encode(['code' => -1, 'msg' => '密码校验失败次数过多，请5分钟后重试'], JSON_UNESCAPED_UNICODE);
        }
        $currentPassword = (string)($request->post('current_password') ?? '');
        $passwordHash = (string)($merchant->password_hash ?? '');
        $verified = $passwordHash !== ''
            ? password_verify($currentPassword, $passwordHash)
            : hash_equals((string)$merchant->key, $currentPassword);
        if (!$verified) {
            return json_encode(['code' => -1, 'msg' => '当前登录密码错误，API 密钥未变更'], JSON_UNESCAPED_UNICODE);
        }

        $newKey = bin2hex(random_bytes(24));
        $merchant->key = $newKey;
        $merchant->save();
        LoginRateLimiter::clear('merchant_key_reset', $rateLimitId);
        try {
            \Webman\Redis\Client::connection()->del('cx:merchant_key:' . $merchant->pid);
        } catch (\Throwable) {
        }

        return json_encode([
            'code' => 1,
            'msg' => '对接密钥重置成功，请立即保存；离开页面后将不再显示',
            'new_key' => $newKey,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 修改商户后台登录密码，不影响开放 API 签名密钥。
     */
    public function changePassword(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json_encode(['code' => 401, 'msg' => '商户身份无效'], JSON_UNESCAPED_UNICODE);
        }

        $currentPassword = (string)($request->post('current_password') ?? '');
        $newPassword = (string)($request->post('new_password') ?? '');
        if (strlen($newPassword) < 10 || strlen($newPassword) > 200) {
            return json_encode(['code' => -1, 'msg' => '新密码长度必须为10至200个字符'], JSON_UNESCAPED_UNICODE);
        }

        $rateLimitId = $request->getRemoteIp() . '|' . (string)$merchant->id;
        if (LoginRateLimiter::tooManyAttempts('merchant_password_change', $rateLimitId, 5, 300)) {
            return json_encode(['code' => -1, 'msg' => '密码校验失败次数过多，请5分钟后重试'], JSON_UNESCAPED_UNICODE);
        }
        $passwordHash = (string)($merchant->password_hash ?? '');
        $verified = $passwordHash !== ''
            ? password_verify($currentPassword, $passwordHash)
            : hash_equals((string)$merchant->key, $currentPassword);
        if (!$verified) {
            return json_encode(['code' => -1, 'msg' => '当前登录密码错误'], JSON_UNESCAPED_UNICODE);
        }

        $merchant->password_hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $merchant->save();
        LoginRateLimiter::clear('merchant_password_change', $rateLimitId);
        $request->sessionRegenerateId(true);

        return json_encode(['code' => 1, 'msg' => '登录密码修改成功，API 签名密钥未改变'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 商户余额充值 / 购买 VIP 套餐
     */
    public function buyVip(\support\Request $request): string
    {
        return json_encode([
            'code' => -1,
            'msg' => 'VIP 购买尚未接入支付结算，已禁止直接免费开通',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function currentMerchant(\support\Request $request): ?Merchant
    {
        $merchant = $request->context['merchant'] ?? null;
        if ($merchant instanceof Merchant) {
            return $merchant;
        }

        $merchantId = (int)$request->session()->get('merchant_id', 0);
        return $merchantId > 0 ? Merchant::find($merchantId) : null;
    }
}
