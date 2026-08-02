<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Merchant;
use app\model\Order;
use support\LoginRateLimiter;
use support\Authcode;
use app\service\AlertNotificationService;
use app\service\OrderService;

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
    /**
     * 商户自主在线注册
     */
    public function register(\support\Request $request): string
    {
        $params   = $request->post();
        $name     = trim((string)($params['name'] ?? ''));
        $password = (string)($params['password'] ?? '');

        if (empty($name) || mb_strlen($name) > 100) {
            return json_encode(['code' => -1, 'msg' => '商户名称不能为空且不得超过100个字符'], JSON_UNESCAPED_UNICODE);
        }
        if (strlen($password) < 6 || strlen($password) > 200) {
            return json_encode(['code' => -1, 'msg' => '登录密码长度至少为 6 个字符'], JSON_UNESCAPED_UNICODE);
        }

        // 检查商户名称是否重复
        if (Merchant::where('name', $name)->exists()) {
            return json_encode(['code' => -1, 'msg' => '该商户名称已被注册，请更换其他名称'], JSON_UNESCAPED_UNICODE);
        }

        $rateLimitId = $request->getRemoteIp();
        if (LoginRateLimiter::tooManyAttempts('merchant_register', $rateLimitId, 3, 600)) {
            return json_encode(['code' => -1, 'msg' => '注册频繁，请10分钟后再试'], JSON_UNESCAPED_UNICODE);
        }

        try {
            // 自动生成唯一 PID (1000 + 自增 / 或 16 进制串)
            $lastId = (int)Merchant::max('id') ?: 0;
            $pid = (string)(1000 + $lastId + 1);
            while (Merchant::where('pid', $pid)->exists()) {
                $pid = (string)((int)$pid + 1);
            }

            $apiKey = bin2hex(random_bytes(16));
            $passHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $merchant = new Merchant();
            $merchant->name = $name;
            $merchant->pid = $pid;
            $merchant->key = $apiKey;
            $merchant->password_hash = $passHash;
            $merchant->rate = 0.0200; // 默认标准套餐扣率 2%
            $merchant->status = 1;
            $merchant->save();

            // 注册成功自动完成 Session 登录
            $session = $request->session();
            $session->set('merchant_id', $merchant->id);
            $request->sessionRegenerateId(true);

            return json_encode([
                'code' => 1,
                'msg'  => '注册成功！系统已为您自动分配 PID，正在进入控制台...',
                'data' => [
                    'pid'     => $pid,
                    'api_key' => $apiKey,
                ]
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['code' => -1, 'msg' => '商户注册失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

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
            $q->where('pid', $account)->orWhere('id', $account)->orWhere('name', $account);
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

                // 异步派发商户登录通知
                try {
                    $alertSvc = new AlertNotificationService();
                    $pid = (string)($merchant->pid ?? $merchant->id);
                    $alertSvc->dispatchAdmin('merchant_login', ['pid' => $pid, 'ip' => $request->getRemoteIp()]);
                    $alertSvc->dispatchMerchant($pid, 'merchant_login', ['pid' => $pid, 'ip' => $request->getRemoteIp()]);
                } catch (\Throwable) {
                }

                return json_encode([
                    'code' => 1,
                    'msg'  => '商户登录成功！正在跳转控制台...',
                    'data' => [
                        'pid'  => $merchant->pid ?? $merchant->id,
                        'name' => $merchant->name
                    ]
                ], JSON_UNESCAPED_UNICODE);
            }
        } else {
            // 账号不存在或已停用：执行 dummy bcrypt 验证统一响应时间，防止时序旁路枚举账号
            password_verify($password, '$2y$12$dummyhashfortimingnormalization00000000000000000000000');
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

        // 若商户 KEY 为空，自动生成并保存密钥
        if (trim((string)$merchant->key) === '') {
            $merchant->key = bin2hex(random_bytes(16));
            $merchant->save();
            try {
                \Webman\Redis\Client::connection()->del('cx:merchant_key:' . $merchant->pid);
            } catch (\Throwable) {}
        }

        return json_encode([
            'code' => 1,
            'data' => [
                'pid'            => $merchant->pid,
                'name'           => $merchant->name,
                'key'            => (string)($merchant->key ?? ''),
                'key_configured' => trim((string)$merchant->key) !== '',
                'money'          => number_format((float)($merchant->money ?? 0), 2, '.', ''),
                'rate'           => (float)($merchant->rate ?? 0.02),
                'packvip_time'   => $merchant->packvip_time ? date('Y-m-d H:i:s', $merchant->packvip_time) : '未开通',
                'status'         => $merchant->status,
                'gateway_url'    => $gatewayBase !== '' ? $gatewayBase . '/submit.php' : '',
                'site_url'       => $gatewayBase !== '' ? $gatewayBase . '/merchant_login.html' : '',
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
        if (strlen($newPassword) < 6 || strlen($newPassword) > 200) {
            return json_encode(['code' => -1, 'msg' => '新密码长度至少为6个字符'], JSON_UNESCAPED_UNICODE);
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

    /**
     * 获取商户控制台 Dashboard 动态汇总统计
     */
    public function getDashboardData(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json_encode(['code' => 401, 'msg' => '未找到商户信息'], JSON_UNESCAPED_UNICODE);
        }

        $merchantId = (int)$merchant->id;
        $cacheKey   = 'cx:merchant_dashboard:' . $merchantId;
        $cacheTtl   = 10; // 10秒缓存，平衡实时性与性能

        // 尝试从 Redis 读取缓存
        try {
            $redis  = \Webman\Redis\Client::connection();
            $cached = $redis->get($cacheKey);
            if ($cached) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    // 余额实时读取，不走缓存（避免余额显示滞后）
                    $decoded['money'] = number_format((float)($merchant->money ?? 0), 2, '.', '');
                    return json_encode(['code' => 1, 'data' => $decoded], JSON_UNESCAPED_UNICODE);
                }
            }
        } catch (\Throwable) {
            $redis = null;
        }

        $todayStart = strtotime(date('Y-m-d 00:00:00'));

        // 使用 selectRaw 聚合，避免全量对象加载到 PHP 内存
        $todayStats = \Illuminate\Database\Capsule\Manager::table('cx_order')
            ->selectRaw(
                'SUM(CASE WHEN status = 1 AND pay_time >= ? THEN price ELSE 0 END) as today_amount,
                 SUM(CASE WHEN status = 1 AND pay_time >= ? THEN 1 ELSE 0 END) as today_count,
                 SUM(CASE WHEN create_time >= ? THEN 1 ELSE 0 END) as today_total',
                [$todayStart, $todayStart, $todayStart]
            )
            ->where('merchant_id', $merchantId)
            ->first();

        $todayAmount = (float)($todayStats->today_amount ?? 0);
        $todayCount  = (int)($todayStats->today_count ?? 0);
        $todayTotal  = (int)($todayStats->today_total ?? 0);
        $successRate = $todayTotal > 0
            ? number_format(($todayCount / $todayTotal) * 100, 1)
            : '100.0';

        // 运行中通道数量（已启用）
        $runningChannelsCount = \app\model\Channel::where('merchant_id', $merchantId)
            ->where('status', 1)
            ->count();

        $data = [
            'today_amount'          => number_format($todayAmount, 2, '.', ''),
            'today_count'           => $todayCount,
            'today_success_rate'    => $successRate,
            'running_channel_count' => $runningChannelsCount,
            'money'                 => number_format((float)($merchant->money ?? 0), 2, '.', ''),
            'rate'                  => (float)($merchant->rate ?? 0.02),
        ];

        // 写入缓存（余额字段不缓存，每次实时读取）
        try {
            if (isset($redis)) {
                $cacheData = $data;
                unset($cacheData['money']); // 余额实时，不缓存
                $redis->setex($cacheKey, $cacheTtl, json_encode($cacheData, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable) {
        }

        return json_encode(['code' => 1, 'data' => $data], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 获取商户服务费/资金变动明细
     */
    public function getFinanceLogs(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json_encode(['code' => 401, 'msg' => '未找到商户信息'], JSON_UNESCAPED_UNICODE);
        }

        $logs = \app\model\UserMoneyLog::where('merchant_id', $merchant->id)
            ->orderBy('id', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($log) {
                return [
                    'id'          => $log->id,
                    'money'       => $log->money,
                    'before'      => $log->before,
                    'after'       => $log->after,
                    'memo'        => $log->memo,
                    'create_time' => date('Y-m-d H:i:s', (int)$log->create_time),
                ];
            });

        return json_encode([
            'code' => 1,
            'data' => $logs
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

    /**
     * 获取商户自己的通知配置
     */
    public function getAlertConfig(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
        }
        $config = (new AlertNotificationService())->getMerchantConfig((string)$merchant->pid);
        return json_encode(['code' => 1, 'data' => $config], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 商户保存自己的通知配置
     */
    public function saveAlertConfig(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
        }
        $result = (new AlertNotificationService())->saveMerchantConfig((string)$merchant->pid, $request->post());
        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 商户发送测试通知
     */
    public function testAlert(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
        }
        $channel = trim((string)($request->post('channel') ?? ''));
        if (!in_array($channel, ['email', 'wxwork', 'webhook'], true)) {
            return json_encode(['code' => -1, 'msg' => '无效的通知渠道'], JSON_UNESCAPED_UNICODE);
        }
        $scope = 'merchant_' . preg_replace('/[^A-Za-z0-9_]/', '_', (string)$merchant->pid);
        $ok    = (new AlertNotificationService())->sendTest($scope, $channel);
        return json_encode([
            'code' => $ok ? 1 : -1,
            'msg'  => $ok ? '测试通知已发送，请检查接收端' : '发送失败，请检查配置是否正确',
        ], JSON_UNESCAPED_UNICODE);
    }
    /**
     * 商户主动重发已支付订单的异步通知
     *
     * POST /api/merchant/order/resend_notify
     * Body: { trade_no: "CXxxxxxxxxxx" }
     *
     * 安全策略：
     *   - 订单必须属于当前登录商户
     *   - 订单状态必须为已支付（status=1）
     *   - 同一订单每小时最多重发 3 次（Redis 限频）
     */
    public function resendOrderNotify(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
        }

        $tradeNo = trim((string)($request->post('trade_no') ?? ''));
        if ($tradeNo === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $tradeNo)) {
            return json_encode(['code' => -1, 'msg' => 'trade_no 格式不合法'], JSON_UNESCAPED_UNICODE);
        }

        // 验证订单属于当前商户
        $order = Order::where('trade_no', $tradeNo)
            ->where('merchant_id', $merchant->id)
            ->first();
        if (!$order) {
            return json_encode(['code' => -1, 'msg' => '订单不存在或无权操作'], JSON_UNESCAPED_UNICODE);
        }
        if ((int)$order->status !== 1) {
            return json_encode(['code' => -1, 'msg' => '仅已支付订单可重发通知'], JSON_UNESCAPED_UNICODE);
        }

        // Redis 限频：单订单每小时最多 3 次
        try {
            $redis    = \Webman\Redis\Client::connection();
            $limitKey = 'cx:resend_limit:' . $tradeNo;
            $count    = (int)$redis->get($limitKey);
            if ($count >= 3) {
                return json_encode([
                    'code' => -1,
                    'msg'  => '该订单本小时内已重发 3 次，请下一小时后再试',
                ], JSON_UNESCAPED_UNICODE);
            }
            $redis->incr($limitKey);
            $redis->expire($limitKey, 3600); // 1 小时过期
        } catch (\Throwable $e) {
            // Redis 不可用时不限制重发（fail-open）
            error_log('[MerchantApiController] 重发限频 Redis 不可用: ' . $e->getMessage());
        }

        $result = (new OrderService())->resendNotify($tradeNo);
        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }
}

