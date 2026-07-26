<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Merchant;
use app\model\Order;
use app\service\OrderService;
use support\Response;
use Throwable;

/**
 * 商户在线自主充值余额 API 控制器
 */
class MerchantRechargeController
{
    protected OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    /**
     * 发起在线充值余额订单 /api/merchant/recharge/create
     */
    public function create(object $request): Response
    {
        try {
            $pid    = $request->post('pid') ?? $request->get('pid') ?? '';
            $money  = (float)($request->post('money') ?? 0);
            $type   = (string)($request->post('type') ?? 'alipay');

            if (empty($pid) || $money <= 0) {
                return json(['code' => -1, 'msg' => '商户 PID 与充值金额 (money) 不能为空且必须大于0']);
            }

            $merchant = Merchant::where('pid', $pid)->first();
            if (!$merchant) {
                return json(['code' => -1, 'msg' => '商户不存在']);
            }

            // 生成在线充值订单
            $outTradeNo = 'RECHARGE_' . time() . mt_rand(100, 999);
            $params = [
                'pid'          => $pid,
                'out_trade_no' => $outTradeNo,
                'notify_url'   => 'http://' . ($request->host() ?? '127.0.0.1') . '/notify/' . $type,
                'return_url'   => 'http://' . ($request->host() ?? '127.0.0.1') . '/merchant_center.html',
                'name'         => '商户账户余额充值 ¥' . number_format($money, 2, '.', ''),
                'money'        => $money,
                'type'         => $type,
                'param'        => 'recharge:' . $merchant->id,
            ];

            // 验签模拟
            $res = $this->orderService->createOrder(array_merge($params, [
                'sign' => \support\Sign::makeSign($params, $merchant->key)
            ]));

            return json(['code' => 1, 'msg' => '充值订单创建成功', 'data' => $res]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }
}
