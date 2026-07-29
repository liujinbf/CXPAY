<?php

namespace app\api\controller;

use app\common\controller\Frontend;
use app\common\model\PayChannel;
use app\common\service\OrderService;
use app\common\service\PayException;

/**
 * 商户中心 - 在线充值
 *
 * 商户向平台收款账号(payment.recharge_uid)付款，付款成功后在结算阶段给本商户加余额。
 * 充值订单以 param="recharge:{商户id}" 标记，结算命中时加余额、不扣费、不外发回调。
 */
class Recharge extends Frontend
{
    protected array $noNeedLogin = [];

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 充值信息：是否开放 + 可用支付方式 + 单笔限额
     */
    public function info(): void
    {
        [$rechargeUid, $min, $max] = OrderService::rechargeConfig();
        $types = [];
        if ($rechargeUid > 0) {
            $rows = PayChannel::where(['uid' => $rechargeUid, 'status' => 1, 'tt_switch' => 'true'])
                ->distinct(true)->column('type');
            $map = ['alipay' => '支付宝', 'wxpay' => '微信', 'qqpay' => 'QQ钱包'];
            foreach ($rows as $t) {
                $types[] = ['type' => $t, 'name' => $map[$t] ?? $t];
            }
        }

        $this->success('', [
            'enabled' => $rechargeUid > 0 && !empty($types),
            'types'   => $types,
            'min'     => $min,
            'max'     => $max,
        ]);
    }

    /**
     * 提交充值：创建充值订单，返回收银台地址
     */
    public function submit(): void
    {
        $type   = (string) $this->request->param('type', 'alipay');
        $amount = (string) $this->request->param('amount', '');

        try {
            $res = (new OrderService())->createRechargeOrder($this->auth->id, $type, $amount);
        } catch (PayException $e) {
            $this->error($e->getMessage());
        }

        $this->success('已创建充值订单', $res);
    }
}
