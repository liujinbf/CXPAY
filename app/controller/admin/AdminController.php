<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Merchant;
use app\model\Order;
use app\model\Channel;
use app\service\MonitorService;
use app\service\OrderService;
use app\service\AlertNotificationService;
use app\payment\PaymentManager;
use support\Authcode;
use support\AuditLog;
use Illuminate\Database\Capsule\Manager as DB;
use Exception;
use support\LoginRateLimiter;
use support\IpWhitelist;

/**
 * 管理员后台商户管理、通道配置与系统实时监控 API Controller
 */
class AdminController
{
    protected Authcode $authcode;
    protected MonitorService $monitorService;
    protected OrderService $orderService;

    public function __construct()
    {
        $this->authcode       = new Authcode();
        $this->monitorService = new MonitorService();
        $this->orderService   = new OrderService();
    }

    /**
     * 管理员登录认证接口
     */
    public function login(\support\Request $request): string
    {
        $params   = $request->post();
        $account  = trim((string)($params['account'] ?? ''));
        $password = trim((string)($params['password'] ?? ''));

        if (empty($account) || empty($password)) {
            return json_encode(['code' => -1, 'msg' => '管理员账号与密码不能为空'], JSON_UNESCAPED_UNICODE);
        }

        $rateLimitId = $request->getRemoteIp() . '|' . strtolower($account);
        if (LoginRateLimiter::tooManyAttempts('admin', $rateLimitId)) {
            return json_encode(['code' => -1, 'msg' => '登录失败次数过多，请5分钟后重试'], JSON_UNESCAPED_UNICODE);
        }

        // 从数据库读取管理员账号和 bcrypt 密码哈希
        $row = DB::table('cx_config')->where('name', 'admin_account')->first();
        $storedAccount = $row ? (string)$row->value : 'admin';

        $rowPwd = DB::table('cx_config')->where('name', 'admin_password_hash')->first();
        $storedHash = $rowPwd ? (string)$rowPwd->value : '';

        // 兼容旧版：若数据库中仍为明文密码，自动迁移为 bcrypt
        if (!empty($storedHash) && !str_starts_with($storedHash, '$2y$')) {
            // 旧版明文直接比对（仅迁移期间使用）
            if ($account !== $storedAccount || $password !== $storedHash) {
                return json_encode(['code' => -1, 'msg' => '管理员账号或密码错误'], JSON_UNESCAPED_UNICODE);
            }
            // 自动升级为 bcrypt 存储
            $newHash = password_hash($storedHash, PASSWORD_BCRYPT, ['cost' => 12]);
            DB::table('cx_config')->where('name', 'admin_password_hash')
                ->update(['value' => $newHash, 'title' => '管理员密码Bcrypt哈希']);
            $storedHash = $newHash;
        } elseif (empty($storedHash)) {
            // 未配置任何密码，拒绝登录
            return json_encode(['code' => -1, 'msg' => '系统尚未初始化管理员密码，请联系部署人员'], JSON_UNESCAPED_UNICODE);
        }

        // 校验账号 + bcrypt 密码
        if ($account !== $storedAccount || !password_verify($password, $storedHash)) {
            return json_encode(['code' => -1, 'msg' => '管理员账号或密码错误'], JSON_UNESCAPED_UNICODE);
        }
        if (password_needs_rehash($storedHash, PASSWORD_BCRYPT, ['cost' => 12])) {
            $storedHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            DB::table('cx_config')->where('name', 'admin_password_hash')->update(['value' => $storedHash]);
        }

        // 生成含签名的 Token（账号 + 时间戳 + 服务端盐，存入 Session 绑定）
        $tokenSalt = (string)DB::table('cx_config')->where('name', 'token_salt')->value('value');
        if (strlen($tokenSalt) < 32) {
            return json_encode(['code' => -1, 'msg' => '系统 Token 盐未安全初始化，请重新执行安装配置'], JSON_UNESCAPED_UNICODE);
        }
        $tokenExpire = time() + 7200; // 2小时有效期
        $tokenRaw    = $account . '|' . $tokenExpire;
        $tokenSign   = hash_hmac('sha256', $tokenRaw, $tokenSalt);
        $token       = base64_encode($tokenRaw . '|' . $tokenSign);

        $session = $request->session();
        $session->set('admin_info', [
            'username'     => $account,
            'login_time'   => time(),
            'token_expire' => $tokenExpire,
            'role'         => 'root',
        ]);
        $request->sessionRegenerateId(true);
        LoginRateLimiter::clear('admin', $rateLimitId);

        // 异步派发管理员登录通知
        try {
            (new AlertNotificationService())->dispatchAdmin('admin_login', [
                'ip' => $request->getRemoteIp(),
            ]);
        } catch (\Throwable) {
        }

        return json_encode([
            'code' => 1,
            'msg'  => '登录验证成功！正在跳转总控台...',
            'data' => [
                'token'    => $token,
                'username' => $account,
                'expire'   => $tokenExpire,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 管理员退出登录
     */
    public function logout(\support\Request $request): string
    {
        $request->session()->forget('admin_info');
        $request->sessionRegenerateId(true);
        return json_encode(['code' => 1, 'msg' => '已成功退出登录'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 获取全网统计概览数据与系统实时性能监控指标
     * 统计数据使用 Redis 缓存 30 秒，避免大数据量下频繁全表扫描。
     */
    public function dashboard(\support\Request $request): string
    {
        $stats = $this->getDashboardStats();

        // 采集硬件与运行进程指标（实时，不缓存）
        $systemMetrics = $this->monitorService->getMetrics();

        return json_encode([
            'code' => 1,
            'data' => array_merge($stats, ['metrics' => $systemMetrics]),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 读取/刷新 Dashboard 统计缓存。
     * 写操作（补单/关单等）应主动调用此方法令缓存失效。
     */
    private function getDashboardStats(): array
    {
        $cacheKey = 'cx:dashboard_stats';
        $cacheTtl = 30;

        try {
            $redis = \Webman\Redis\Client::connection();
            $cached = $redis->get($cacheKey);
            if ($cached) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable) {
            $redis = null;
        }

        // 合并聚合查询减少数据库往返
        $orderStats = DB::table('cx_order')
            ->selectRaw('
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as paid_orders,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as closed_orders,
                SUM(CASE WHEN status = 1 THEN amount ELSE 0 END) as total_amount
            ')
            ->first();

        $merchantStats = DB::table('cx_merchant')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN packvip_id > 0 AND packvip_time > ? THEN 1 ELSE 0 END) as vip
            ', [time()])
            ->first();

        $channelStats = DB::table('cx_channel')
            ->selectRaw('
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as enabled,
                SUM(CASE WHEN status = 1 AND online_status = 1 THEN 1 ELSE 0 END) as online
            ')
            ->first();

        $paidOrders   = (int)($orderStats->paid_orders ?? 0);
        $closedOrders = (int)($orderStats->closed_orders ?? 0);
        // 成功率 = 已支付 / (已支付 + 已关闭)，剔除仍处于待支付中的订单干扰。
        $settledOrders = $paidOrders + $closedOrders;
        $successRate   = $settledOrders > 0
            ? sprintf('%.2f%%', ($paidOrders / $settledOrders) * 100)
            : '100.00%';

        $result = [
            'total_amount'          => number_format((float)($orderStats->total_amount ?? 0), 2, '.', ''),
            'total_orders'          => (int)($orderStats->total_orders ?? 0),
            'paid_orders'           => $paidOrders,
            'merchant_count'        => (int)($merchantStats->total ?? 0),
            'active_merchant_count' => (int)($merchantStats->active ?? 0),
            'vip_merchant_count'    => (int)($merchantStats->vip ?? 0),
            'channel_count'         => (int)($channelStats->enabled ?? 0),
            'online_channel_count'  => (int)($channelStats->online ?? 0),
            'success_rate'          => $successRate,
        ];

        try {
            if (isset($redis)) {
                $redis->setex($cacheKey, $cacheTtl, json_encode($result, JSON_UNESCAPED_UNICODE));
            }
        } catch (\Throwable) {
        }

        return $result;
    }


    /**
     * 分页获取商户列表，敏感登录哈希与 API 密钥永不下发。
     */
    public function listMerchants(\support\Request $request): string
    {
        $keyword = trim((string)$request->get('keyword', ''));
        $pageSize = max(1, min(100, (int)$request->get('page_size', 20)));
        if (mb_strlen($keyword) > 100) {
            return json_encode(['code' => -1, 'msg' => '搜索关键词过长'], JSON_UNESCAPED_UNICODE);
        }

        $query = Merchant::query()->select([
            'id', 'pid', 'name', 'money', 'rate', 'packvip_id', 'packvip_time',
            'ip_white', 'status', 'create_time',
        ]);
        if ($keyword !== '') {
            $escaped = addcslashes($keyword, '%_\\');
            $query->where(function ($builder) use ($escaped): void {
                $builder->where('pid', 'like', "%{$escaped}%")
                    ->orWhere('name', 'like', "%{$escaped}%");
            });
        }

        return json_encode([
            'code' => 1,
            'data' => $query->orderByDesc('id')->paginate($pageSize),
        ], JSON_UNESCAPED_UNICODE);
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
                'id'         => $channel->id,
                'pay_category' => $channel->pay_category,
                'title'      => $channel->title,
                'c_type'     => $channel->c_type,
                'remark'     => $channel->remark,
                'weight'     => $channel->weight,
                'single_min' => $channel->single_min,
                'single_max' => $channel->single_max,
                'day_max'    => $channel->day_max,
                'status'     => $channel->status,
                'config'     => $decryptedConfig,
                'configured' => $configured,
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
                'last_heartbeat_time', 'status',
            ])
            ->orderByDesc('id')
            ->get();

        return json_encode(['code' => 1, 'data' => $channels], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 保存/更新支付通道、权重与私钥配置 (Authcode 加密存储)
     */
    public function saveChannelConfig(\support\Request $request): string
    {
        $params    = $request->post();
        $channelId = (int)($params['id'] ?? 0);
        $cType     = trim((string)($params['c_type'] ?? ''));
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

        $weight = (int)($params['weight'] ?? 50);
        $singleMin = (float)($params['single_min'] ?? 0);
        $singleMax = (float)($params['single_max'] ?? 0);
        $dayMax = (float)($params['day_max'] ?? 0);
        if ($weight < 0 || $weight > 10000 || $singleMin < 0 || $singleMax < 0 || $dayMax < 0
            || ($singleMax > 0 && $singleMin > $singleMax)) {
            return json_encode(['code' => -1, 'msg' => '通道权重或金额限制不合法'], JSON_UNESCAPED_UNICODE);
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
            'merchant_id'=> 0,
            'pay_category' => $payCategory,
            'title'      => $title,
            'c_type'     => $cType,
            'remark'     => $remark,
            'config'     => json_encode($encryptedConfig, JSON_UNESCAPED_UNICODE),
            'weight'     => $weight,
            'single_min' => $singleMin,
            'single_max' => $singleMax,
            'day_max'    => $dayMax,
            'status'     => (int)($params['status'] ?? 1) === 1 ? 1 : 0,
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

    /**
     * 新增 / 更新商户账号与费率折扣
     */
    public function saveMerchant(\support\Request $request): string
    {
        $params = $request->post();
        $id     = (int)($params['id'] ?? 0);

        $name = trim((string)($params['name'] ?? '新商户'));
        $submittedKey = trim((string)($params['key'] ?? ''));
        $loginPassword = (string)($params['login_password'] ?? '');
        $rate = (float)($params['rate'] ?? 0.02);
        if ($name === '' || mb_strlen($name) > 100 || $rate < 0 || $rate > 1) {
            return json_encode(['code' => -1, 'msg' => '商户名称、密钥或费率格式不合法'], JSON_UNESCAPED_UNICODE);
        }
        if ($submittedKey !== '' && (strlen($submittedKey) < 32 || strlen($submittedKey) > 64)) {
            return json_encode(['code' => -1, 'msg' => 'API 密钥长度必须为32至64个字符'], JSON_UNESCAPED_UNICODE);
        }
        if ($loginPassword !== '' && (strlen($loginPassword) < 10 || strlen($loginPassword) > 200)) {
            return json_encode(['code' => -1, 'msg' => '商户登录密码长度必须为10至200个字符'], JSON_UNESCAPED_UNICODE);
        }
        $ipWhitelist = IpWhitelist::normalize((string)($params['ip_white'] ?? ''));
        if ($ipWhitelist === null) {
            return json_encode(['code' => -1, 'msg' => 'IP 白名单格式不合法，仅支持最多50个 IPv4/IPv6 地址'], JSON_UNESCAPED_UNICODE);
        }

        $merchantData = [
            'name'       => $name,
            'rate'       => $rate,
            'ip_white'   => $ipWhitelist,
            'status'     => (int)($params['status'] ?? 1) === 1 ? 1 : 0,
        ];

        if ($id > 0) {
            $merchant = Merchant::find($id);
            if (!$merchant) {
                return json_encode(['code' => -1, 'msg' => '商户不存在'], JSON_UNESCAPED_UNICODE);
            }
            // 编辑资料时不默认轮换 API 密钥；只有管理员明确提交新密钥才更新。
            if ($submittedKey !== '') {
                $merchantData['key'] = $submittedKey;
            }
            if ($loginPassword !== '') {
                $merchantData['password_hash'] = password_hash($loginPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            }
            $merchant->fill($merchantData);
            $merchant->save();
            $msg = '商户更新成功';
            $initialPassword = null;
        } else {
            $pid = trim((string)($params['pid'] ?? '')) ?: ('M' . strtoupper(bin2hex(random_bytes(6))));
            if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $pid) || Merchant::where('pid', $pid)->exists()) {
                return json_encode(['code' => -1, 'msg' => '商户 PID 格式不合法或已存在'], JSON_UNESCAPED_UNICODE);
            }
            $key = $submittedKey !== '' ? $submittedKey : bin2hex(random_bytes(24));
            $merchantData['pid'] = $pid;
            $merchantData['key'] = $key;
            $merchantData['money'] = 0.00;
            $merchantData['create_time'] = time();
            $initialPassword = $loginPassword !== ''
                ? $loginPassword
                : rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
            $merchantData['password_hash'] = password_hash($initialPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            Merchant::create($merchantData);
            $msg = '新商户开户成功';
        }

        return json_encode([
            'code' => 1,
            'msg' => $msg,
            'data' => [
                'pid' => $id ? (string)$merchant->pid : $pid,
                'api_key' => $id > 0 ? ($submittedKey !== '' ? $submittedKey : null) : $key,
                'initial_password' => $initialPassword,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 管理员强制补单
     */
    public function forceNotifyOrder(\support\Request $request): string
    {
        $params   = $request->post();
        $tradeNo  = $params['trade_no'] ?? '';
        $operator = AuditLog::currentOperator();
        $ip       = AuditLog::currentIp();

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            AuditLog::record($operator, 'force_pay', ['trade_no' => $tradeNo, 'reason' => '订单不存在'], 'fail', $ip);
            return json_encode(['code' => -1, 'msg' => '订单不存在'], JSON_UNESCAPED_UNICODE);
        }

        if ((int)$order->status === 1) {
            AuditLog::record($operator, 'resend_notify', ['trade_no' => $tradeNo], 'success', $ip);
            return json_encode($this->orderService->resendNotify((string)$order->trade_no), JSON_UNESCAPED_UNICODE);
        }

        $success = $this->orderService->markAsPaid(
            (string)$order->trade_no,
            'MANUAL_' . time(),
            (float)$order->price,
            (int)$order->channel_id,
            false
        );

        AuditLog::record($operator, 'force_pay', [
            'trade_no'   => $tradeNo,
            'amount'     => (string)$order->price,
            'channel_id' => (int)$order->channel_id,
        ], $success ? 'success' : 'fail', $ip);

        if (!$success) {
            return json_encode(['code' => -1, 'msg' => '补单失败，订单状态不允许核销'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'code' => 1,
            'msg'  => '订单已按统一结算流程补单，商户通知已进入队列',
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 保存官网主页模版选择
     */
    public function saveTemplate(\support\Request $request): string
    {
        $params = $request->post();
        $templateName = trim((string)($params['template'] ?? 'default'));
        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $templateName)
            || !is_file(base_path() . "/public/home_templates/{$templateName}.html")) {
            return json_encode(['code' => -1, 'msg' => '主页模板不存在或名称不合法'], JSON_UNESCAPED_UNICODE);
        }

        DB::table('cx_config')
            ->updateOrInsert(
                ['name' => 'active_home_template'],
                ['value' => $templateName]
            );

        return json_encode(['code' => 1, 'msg' => '官网主页模版保存生效成功'], JSON_UNESCAPED_UNICODE);
    }

    private function isSensitiveConfigName(string $name): bool
    {
        return preg_match('/(?:key|secret|token|password|private|cookie|cert)/i', $name) === 1;
    }
}
