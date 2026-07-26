<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Merchant;
use app\model\Order;
use support\Authcode;
use support\Sign;
use Exception;

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
     * 获取当前商户个人资料与对账概览
     */
    public function getProfile(object $request): string
    {
        $pid = $request->get('pid') ?? $request->post('pid') ?? '';
        $merchant = Merchant::where('pid', $pid)->first();

        if (!$merchant) {
            return json_encode(['code' => -1, 'msg' => '未找到商户信息'], JSON_UNESCAPED_UNICODE);
        }

        return json_encode([
            'code' => 1,
            'data' => [
                'pid'           => $merchant->pid,
                'name'          => $merchant->name,
                'key'           => $merchant->key,
                'money'         => number_format((float)($merchant->money ?? 0), 2, '.', ''),
                'rate'          => (float)($merchant->rate ?? 0.02),
                'packvip_time'  => $merchant->packvip_time ? date('Y-m-d H:i:s', $merchant->packvip_time) : '未开通',
                'status'        => $merchant->status,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 重置商户对接密钥 (KEY)
     */
    public function resetKey(object $request): string
    {
        $pid = $request->post('pid') ?? '';
        $merchant = Merchant::where('pid', $pid)->first();

        if (!$merchant) {
            return json_encode(['code' => -1, 'msg' => '商户不存在'], JSON_UNESCAPED_UNICODE);
        }

        $newKey = md5(uniqid((string)mt_rand(), true));
        $merchant->key = $newKey;
        $merchant->save();

        return json_encode(['code' => 1, 'msg' => '对接密钥重置成功', 'new_key' => $newKey], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 商户余额充值 / 购买 VIP 套餐
     */
    public function buyVip(object $request): string
    {
        $pid   = $request->post('pid') ?? '';
        $vipId = (int)($request->post('vip_id') ?? 1);

        $merchant = Merchant::find($pid);
        if (!$merchant) {
            return json_encode(['code' => -1, 'msg' => '商户不存在'], JSON_UNESCAPED_UNICODE);
        }

        // 续费 30 天 VIP
        $merchant->packvip_id   = $vipId;
        $merchant->packvip_time = time() + (86400 * 30);
        $merchant->save();

        return json_encode(['code' => 1, 'msg' => 'VIP 套餐升级与续费成功！已开启优惠扣率'], JSON_UNESCAPED_UNICODE);
    }
}
