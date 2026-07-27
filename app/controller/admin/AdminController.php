<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Merchant;
use app\model\Order;
use app\model\Channel;
use app\service\MonitorService;
use app\service\MerchantNotifyService;
use support\Authcode;
use Illuminate\Database\Capsule\Manager as DB;
use Exception;

/**
 * 管理员后台商户管理、通道配置与系统实时监控 API Controller
 */
class AdminController
{
    protected Authcode $authcode;
    protected MonitorService $monitorService;
    protected MerchantNotifyService $notifyService;

    public function __construct()
    {
        $this->authcode       = new Authcode();
        $this->monitorService = new MonitorService();
        $this->notifyService  = new MerchantNotifyService();
    }

    /**
     * 管理员登录认证接口
     */
    public function login(object $request): string
    {
        $params   = $request->post();
        $account  = trim((string)($params['account'] ?? ''));
        $password = trim((string)($params['password'] ?? ''));

        if (empty($account) || empty($password)) {
            return json_encode(['code' => -1, 'msg' => '管理员账号与密码不能为空'], JSON_UNESCAPED_UNICODE);
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

        // 生成含签名的 Token（账号 + 时间戳 + 服务端盐，存入 Session 绑定）
        $tokenSalt   = (string)(DB::table('cx_config')->where('name', 'token_salt')->value('value') ?: 'CX_TOKEN_SALT_DEFAULT');
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
    public function logout(object $request): string
    {
        $request->session()->forget('admin_info');
        return json_encode(['code' => 1, 'msg' => '已成功退出登录'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 获取全网统计概览数据与系统实时性能监控指标
     */
    public function dashboard(object $request): string
    {
        $totalOrders = Order::count();
        $paidOrders  = Order::where('status', 1)->count();
        $totalAmount = Order::where('status', 1)->sum('amount');
        $merchantCount = Merchant::count();

        // 采集硬件与运行进程指标
        $systemMetrics = $this->monitorService->getMetrics();

        return json_encode([
            'code' => 1,
            'data' => [
                'total_amount'   => number_format((float)$totalAmount, 2, '.', ''),
                'total_orders'   => $totalOrders,
                'paid_orders'    => $paidOrders,
                'merchant_count' => $merchantCount,
                'success_rate'   => $totalOrders > 0 ? sprintf('%.2f%%', ($paidOrders / $totalOrders) * 100) : '100.00%',
                'metrics'        => $systemMetrics,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 获取或解密特定通道的加密配置参数
     */
    public function getChannelConfig(object $request): string
    {
        $channelId = (int)($request->get('id') ?? $request->post('id') ?? 0);
        $channel   = Channel::find($channelId);

        if (!$channel) {
            return json_encode(['code' => -1, 'msg' => '通道不存在'], JSON_UNESCAPED_UNICODE);
        }

        $rawConfig = json_decode($channel->config, true) ?: [];
        $decryptedConfig = [];
        foreach ($rawConfig as $k => $v) {
            $decryptedConfig[$k] = is_string($v) ? ($this->authcode->decrypt($v) ?: $v) : $v;
        }

        return json_encode([
            'code' => 1,
            'data' => [
                'id'         => $channel->id,
                'title'      => $channel->title,
                'c_type'     => $channel->c_type,
                'weight'     => $channel->weight,
                'single_min' => $channel->single_min,
                'single_max' => $channel->single_max,
                'day_max'    => $channel->day_max,
                'status'     => $channel->status,
                'config'     => $decryptedConfig,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 保存/更新支付通道、权重与私钥配置 (Authcode 加密存储)
     */
    public function saveChannelConfig(object $request): string
    {
        $params    = $request->post();
        $channelId = (int)($params['id'] ?? 0);
        $rawConfig = $params['config'] ?? [];

        // 使用 Authcode 算法加密存储通道私钥及敏感配置
        $encryptedConfig = [];
        foreach ($rawConfig as $k => $v) {
            $encryptedConfig[$k] = is_string($v) ? $this->authcode->encrypt((string)$v) : $v;
        }

        $updateData = [
            'title'      => $params['title'] ?? '支付通道',
            'c_type'     => $params['c_type'] ?? 'epay_generic',
            'config'     => json_encode($encryptedConfig, JSON_UNESCAPED_UNICODE),
            'weight'     => (int)($params['weight'] ?? 50),
            'single_min' => (float)($params['single_min'] ?? 0),
            'single_max' => (float)($params['single_max'] ?? 0),
            'day_max'    => (float)($params['day_max'] ?? 0),
            'status'     => (int)($params['status'] ?? 1),
        ];

        if ($channelId > 0) {
            Channel::where('id', $channelId)->update($updateData);
            $msg = '通道参数与加密配置修改成功';
        } else {
            Channel::create($updateData);
            $msg = '添加新通道成功';
        }

        return json_encode(['code' => 1, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 新增 / 更新商户账号与费率折扣
     */
    public function saveMerchant(object $request): string
    {
        $params = $request->post();
        $id     = $params['id'] ?? null;

        $merchantData = [
            'name'       => $params['name'] ?? '新商户',
            'key'        => $params['key'] ?? md5(uniqid((string)mt_rand(), true)),
            'rate'       => (float)($params['rate'] ?? 0.02),
            'ip_white'   => $params['ip_white'] ?? '',
            'status'     => (int)($params['status'] ?? 1),
        ];

        if ($id) {
            Merchant::where('id', $id)->update($merchantData);
            $msg = '商户更新成功';
        } else {
            $merchantData['create_time'] = time();
            Merchant::create($merchantData);
            $msg = '新商户开户成功';
        }

        return json_encode(['code' => 1, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 管理员强制补单
     */
    public function forceNotifyOrder(object $request): string
    {
        $params  = $request->post();
        $tradeNo = $params['trade_no'] ?? '';

        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return json_encode(['code' => -1, 'msg' => '订单不存在'], JSON_UNESCAPED_UNICODE);
        }

        if ((int)$order->status !== 1) {
            // 仅当未支付时才更新状态
            Order::where('id', $order->id)
                ->where('status', '!=', 1)
                ->update([
                    'status'           => 1,
                    'pay_time'         => time(),
                    'channel_trade_no' => 'MANUAL_' . time(),
                ]);
            $order->refresh();
        }

        // 强制补单后必须触发异步回调通知商户系统
        $notifyResult = $this->notifyService->notifyMerchant($order);

        return json_encode([
            'code' => 1,
            'msg'  => '强制标记订单成功，已触发异步回调通知',
            'data' => ['notify_sent' => $notifyResult],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 保存官网主页模版选择
     */
    public function saveTemplate(object $request): string
    {
        $params = $request->post();
        $templateName = $params['template'] ?? 'default';

        \illuminate\database\capsule\manager::table('cx_config')
            ->updateOrInsert(
                ['name' => 'active_home_template'],
                ['value' => $templateName]
            );

        return json_encode(['code' => 1, 'msg' => '官网主页模版保存生效成功'], JSON_UNESCAPED_UNICODE);
    }
}
