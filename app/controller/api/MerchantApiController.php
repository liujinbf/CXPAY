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

        // 确保商户 KEY 绝对不为空
        $currentKey = trim((string)($merchant->key ?? ''));
        if ($currentKey === '') {
            $currentKey = bin2hex(random_bytes(16));
            \support\Db::table('cx_merchant')->where('id', $merchant->id)->update(['key' => $currentKey]);
            $merchant->key = $currentKey;
            try {
                \Webman\Redis\Client::connection()->del('cx:merchant_key:' . $merchant->pid);
            } catch (\Throwable) {}
        }

        $gatewayBase = rtrim((string)config('app.url', ''), '/');
        if (!filter_var($gatewayBase, FILTER_VALIDATE_URL)) {
            $gatewayBase = '';
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
                'mapi_url'       => $gatewayBase !== '' ? $gatewayBase . '/mapi.php' : '',
                'site_url'       => $gatewayBase !== '' ? $gatewayBase . '/' : '',
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

        $newKey = bin2hex(random_bytes(16));
        \support\Db::table('cx_merchant')->where('id', $merchant->id)->update(['key' => $newKey]);
        $merchant->key = $newKey;

        try {
            \Webman\Redis\Client::connection()->del('cx:merchant_key:' . $merchant->pid);
        } catch (\Throwable) {}

        return json_encode([
            'code' => 1,
            'msg' => '对接密钥已成功重新生成！',
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

        // 获取商户当前生效套餐
        $plan = \app\model\Plan::find((int)$merchant->plan_id);
        $planName = $plan ? $plan->name : '默认基础套餐';
        $expireTime = (int)$merchant->plan_expire_time;
        $expireStr = $expireTime > 0 ? date('Y-m-d H:i:s', $expireTime) : '无到期限制';

        $data = [
            'today_amount'          => number_format($todayAmount, 2, '.', ''),
            'today_count'           => $todayCount,
            'today_success_rate'    => $successRate,
            'running_channel_count' => $runningChannelsCount,
            'money'                 => number_format((float)($merchant->money ?? 0), 2, '.', ''),
            'rate'                  => (float)($merchant->rate ?? 0.02),
            'plan_name'             => $planName,
            'plan_expire_format'    => $expireStr,
            'plan_id'               => (int)$merchant->plan_id,
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

    /**
     * 获取商户端可购买套餐列表
     */
    public function getPlanList(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
        }

        $plans = \app\model\Plan::where('status', 1)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'desc')
            ->get();

        // 统计商户已购买记录
        $boughtCounts = \app\model\MerchantPlanLog::where('merchant_id', $merchant->id)
            ->selectRaw('plan_id, COUNT(*) as cnt')
            ->groupBy('plan_id')
            ->pluck('cnt', 'plan_id')
            ->toArray();

        $planData = $plans->map(function ($plan) use ($boughtCounts, $merchant) {
            $arr = $plan->toArray();
            $boughtCount = (int)($boughtCounts[$plan->id] ?? 0);
            $arr['bought_count'] = $boughtCount;
            $arr['is_current'] = ((int)$merchant->plan_id === (int)$plan->id);
            $arr['can_buy'] = ($plan->limit_count <= 0 || $boughtCount < $plan->limit_count);
            return $arr;
        });

        $expireTimeStr = (int)$merchant->plan_expire_time > 0 ? date('Y-m-d H:i:s', (int)$merchant->plan_expire_time) : '无到期限制/无套餐';
        $currentPlan = \app\model\Plan::find((int)$merchant->plan_id);

        return json_encode([
            'code' => 1,
            'data' => [
                'list'               => $planData,
                'merchant_money'     => number_format((float)$merchant->money, 2, '.', ''),
                'current_plan_id'    => (int)$merchant->plan_id,
                'current_plan_name'  => $currentPlan ? $currentPlan->name : '默认基础套餐',
                'plan_expire_time'   => (int)$merchant->plan_expire_time,
                'plan_expire_format' => $expireTimeStr,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 商户购买/免费领取套餐
     */
    public function buyPlan(\support\Request $request): string
    {
        $merchant = $this->currentMerchant($request);
        if (!$merchant) {
            return json_encode(['code' => 401, 'msg' => '未登录'], JSON_UNESCAPED_UNICODE);
        }

        $planId = (int)$request->post('plan_id', 0);
        $plan = \app\model\Plan::where('id', $planId)->where('status', 1)->first();
        if (!$plan) {
            return json_encode(['code' => -1, 'msg' => '套餐不存在或已停用'], JSON_UNESCAPED_UNICODE);
        }

        // 检查限购次数
        if ($plan->limit_count > 0) {
            $boughtCount = \app\model\MerchantPlanLog::where('merchant_id', $merchant->id)
                ->where('plan_id', $plan->id)
                ->count();
            if ($boughtCount >= $plan->limit_count) {
                return json_encode(['code' => -1, 'msg' => '您已达到该套餐的购买限额 (' . $plan->limit_count . '次)'], JSON_UNESCAPED_UNICODE);
            }
        }

        $price = (float)$plan->price;
        $merchantMoney = (float)$merchant->money;

        if ($price > 0 && $merchantMoney < $price) {
            return json_encode([
                'code' => -1,
                'msg'  => '服务费余额不足，当前余额 ¥' . number_format($merchantMoney, 2) . '，套餐需 ¥' . number_format($price, 2),
            ], JSON_UNESCAPED_UNICODE);
        }

        // 事务处理购买
        \illuminate\database\capsule\manager::transaction(function () use ($merchant, $plan, $price) {
            // 扣减余额（若 $price > 0）
            if ($price > 0) {
                $before = (float)$merchant->money;
                $after  = $before - $price;
                $merchant->money = number_format($after, 2, '.', '');

                // 写入变动日志
                \app\model\FinanceLog::create([
                    'merchant_id' => $merchant->id,
                    'type'        => 'buy_plan',
                    'amount'      => '-' . number_format($price, 2, '.', ''),
                    'before'      => number_format($before, 2, '.', ''),
                    'after'       => number_format($after, 2, '.', ''),
                    'memo'        => "购买/订阅套餐【{$plan->name}】",
                    'create_time' => time(),
                ]);
            }

            // 更新商户套餐信息
            $merchant->plan_id = $plan->id;
            $merchant->rate    = number_format((float)$plan->rate / 100.0, 4, '.', ''); // 保存小数形式，如 2.5% 保存为 0.0250

            if ($plan->channel_quota > 0) {
                $merchant->channel_quota = $plan->channel_quota;
            }

            // 续期天数计算
            $now = time();
            $currentExpire = (int)$merchant->plan_expire_time;
            if ($plan->days > 0) {
                $baseTime = ($currentExpire > $now) ? $currentExpire : $now;
                $merchant->plan_expire_time = $baseTime + ($plan->days * 86400);
            } else {
                // 0 表示无时间限制/永久
                $merchant->plan_expire_time = 0;
            }

            $merchant->save();

            // 记录购买日志
            \app\model\MerchantPlanLog::create([
                'merchant_id' => $merchant->id,
                'plan_id'     => $plan->id,
                'plan_name'   => $plan->name,
                'price'       => number_format($price, 2, '.', ''),
                'days'        => $plan->days,
                'rate'        => number_format((float)$plan->rate, 2, '.', ''),
                'create_time' => time(),
            ]);
        });

        $actionText = $price > 0 ? '订阅成功！' : '领取试用成功！';
        return json_encode(['code' => 1, 'msg' => $actionText], JSON_UNESCAPED_UNICODE);
    }
}


