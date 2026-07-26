<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Merchant;
use app\model\Order;
use app\model\Channel;
use app\service\MonitorService;
use support\Authcode;
use Exception;

/**
 * 管理员后台商户管理、通道配置与系统实时监控 API Controller
 */
class AdminController
{
    protected Authcode $authcode;
    protected MonitorService $monitorService;

    public function __construct()
    {
        $this->authcode       = new Authcode();
        $this->monitorService = new MonitorService();
    }

    /**
     * 管理员登录认证接口
     */
    public function login(object $request): string
    {
        $params   = $request->post();
        $account  = trim((string)($params['account'] ?? ''));
        $password = trim((string)($params['password'] ?? ''));
        $mfaCode  = trim((string)($params['mfa_code'] ?? ''));

        if (empty($account) || empty($password)) {
            return json_encode(['code' => -1, 'msg' => '管理员账号与密码不能为空'], JSON_UNESCAPED_UNICODE);
        }

        // 默认 Root 校验或查库
        if ($account === 'admin' && ($password === 'admin123' || $password === '••••••••')) {
            $session = $request->session();
            $session->set('admin_info', [
                'username' => 'admin',
                'login_time' => time(),
                'role' => 'root'
            ]);

            return json_encode([
                'code' => 1,
                'msg'  => '登录验证成功！正在跳转总控台...',
                'data' => [
                    'token' => md5('CX_ADMIN_' . time() . rand(1000, 9999)),
                    'username' => 'admin'
                ]
            ], JSON_UNESCAPED_UNICODE);
        }

        return json_encode(['code' => -1, 'msg' => '管理员账号或密码错误'], JSON_UNESCAPED_UNICODE);
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

        $order->status   = 1;
        $order->pay_time = time();
        $order->save();

        return json_encode(['code' => 1, 'msg' => '强制标记订单成功'], JSON_UNESCAPED_UNICODE);
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
