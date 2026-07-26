<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Order;
use support\Response;
use Exception;

/**
 * 管理员后台订单高级查询、强制补单与手动退款控制器
 */
class OrderAdminController
{
    /**
     * 订单高级检索 (支持多条件筛选)
     */
    public function list(object $request): string
    {
        $tradeNo    = $request->get('trade_no') ?? '';
        $merchantId = $request->get('merchant_id') ?? '';
        $status     = $request->get('status') ?? '';

        $query = Order::query();

        if (!empty($tradeNo)) {
            $query->where('trade_no', 'like', "%{$tradeNo}%");
        }

        if (!empty($merchantId)) {
            $query->where('merchant_id', $merchantId);
        }

        if ($status !== '') {
            $query->where('status', (int)$status);
        }

        $orders = $query->orderBy('id', 'desc')->paginate(15);

        return json_encode([
            'code' => 1,
            'data' => $orders
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 手动关闭 / 作废订单
     */
    public function close(object $request): string
    {
        $tradeNo = $request->post('trade_no') ?? '';
        $order   = Order::where('trade_no', $tradeNo)->first();

        if (!$order) {
            return json_encode(['code' => -1, 'msg' => '订单不存在'], JSON_UNESCAPED_UNICODE);
        }

        $order->status = 2; // 2 代表关闭作废
        $order->save();

        return json_encode(['code' => 1, 'msg' => '订单已成功手动作废关闭'], JSON_UNESCAPED_UNICODE);
    }
}
