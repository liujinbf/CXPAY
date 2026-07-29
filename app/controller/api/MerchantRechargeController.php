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
    public function create(\support\Request $request): Response
    {
        try {
            $money  = (float)($request->post('money') ?? 0);
            $type   = (string)($request->post('type') ?? 'alipay');
            $merchant = $request->context['merchant'] ?? null;

            if (!$merchant instanceof Merchant) {
                return json(['code' => -1, 'msg' => '商户身份无效']);
            }
            if ($money <= 0) {
                return json(['code' => -1, 'msg' => '充值金额必须大于0']);
            }

            // 生成在线充值订单
            $outTradeNo = 'RECHARGE_' . time() . '_' . bin2hex(random_bytes(6));
            $params = [
                'pid'          => $merchant->pid,
                'out_trade_no' => $outTradeNo,
                'notify_url'   => '',
                'return_url'   => $this->baseUrl($request) . '/merchant_center.html',
                'name'         => '商户账户余额充值 ¥' . number_format($money, 2, '.', ''),
                'money'        => $money,
                'type'         => $type,
                'param'        => 'recharge:' . $merchant->id,
            ];

            // 验签模拟
            $res = $this->orderService->createOrder(array_merge($params, [
                'sign' => \support\Sign::makeSign($params, $merchant->key)
            ]), $this->baseUrl($request), 'recharge');

            return json(['code' => 1, 'msg' => '充值订单创建成功', 'data' => $res]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    private function baseUrl(\support\Request $request): string
    {
        $configured = (string)config('app.url', '');
        if (filter_var($configured, FILTER_VALIDATE_URL)) {
            return rtrim($configured, '/');
        }
        $forwarded = strtolower((string)$request->header('x-forwarded-proto'));
        $scheme = in_array($forwarded, ['http', 'https'], true)
            ? $forwarded
            : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
        return $scheme . '://' . $request->host();
    }
}
