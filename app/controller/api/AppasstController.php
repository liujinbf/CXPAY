<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\CallbillService;
use support\Response;
use Throwable;

/**
 * 挂机助手 OpenAPI 控制器 (手机 App / PC 挂机推送账单日志)
 */
class AppasstController
{
    protected CallbillService $callbillService;

    public function __construct()
    {
        $this->callbillService = new CallbillService();
    }

    /**
     * 接收挂机设备推送账单心跳 /api/appasst/push
     */
    public function push(object $request): Response
    {
        try {
            $params = $request->get() + $request->post();

            $deviceApp = $params['app'] ?? 'alipay';
            $money     = (float)($params['money'] ?? 0);
            $remark    = $params['remark'] ?? '';

            // 存入账单日志并尝试撮合订单
            $callbill = $this->callbillService->recordBill([
                'device_id' => $params['device_id'] ?? 'PHONE_001',
                'app'       => $deviceApp,
                'money'     => $money,
                'remark'    => $remark,
            ]);

            return json(['code' => 1, 'msg' => '账单上报并实时匹配成功', 'id' => $callbill['id'] ?? 0]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '上报失败: ' . $e->getMessage()]);
        }
    }
}
