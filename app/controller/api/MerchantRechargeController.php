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
            $money    = (float)($request->post('money') ?? 0);
            $type     = (string)($request->post('type') ?? 'alipay');
            $merchant = $request->context['merchant'] ?? null;

            if (!$merchant instanceof Merchant) {
                $merchantId = (int)$request->session()->get('merchant_id', 0);
                if ($merchantId > 0) {
                    $merchant = Merchant::find($merchantId);
                }
            }

            if (!$merchant instanceof Merchant) {
                return json(['code' => 401, 'msg' => '商户未登录或会话已失效']);
            }
            if ($money <= 0) {
                return json(['code' => -1, 'msg' => '充值金额必须大于0']);
            }

            // 生成在线充值订单
            $outTradeNo = 'RECHARGE_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
            $params = [
                'pid'          => (string)$merchant->pid,
                'out_trade_no' => $outTradeNo,
                'notify_url'   => '',
                'return_url'   => $this->baseUrl($request) . '/merchant_center.html',
                'name'         => '商户余额在线充值 ¥' . number_format($money, 2, '.', ''),
                'money'        => number_format($money, 2, '.', ''),
                'type'         => $type,
                'param'        => 'recharge:' . $merchant->id,
            ];

            // 签名并创建订单
            $sign = \support\Sign::makeSign($params, (string)$merchant->key);
            $res  = $this->orderService->createOrder(
                array_merge($params, ['sign' => $sign, 'sign_type' => 'MD5']),
                $this->baseUrl($request),
                'recharge',
                $request->getRemoteIp()
            );

            return json([
                'code' => 1,
                'msg'  => '充值订单创建成功',
                'data' => [
                    'order_no'        => $res['trade_no'],
                    'trade_no'        => $res['trade_no'],
                    'out_trade_no'    => $outTradeNo,
                    'money'           => $res['money'],
                    'price'           => $res['price'],
                    'pay_type'        => $type,
                    'pay_url'         => $res['pay_url'],
                    'qr_code_content' => $res['pay_url'],
                    'pay_mode'        => $res['pay_mode'],
                ]
            ]);
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
