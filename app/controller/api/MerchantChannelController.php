<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Channel;
use app\model\Merchant;
use app\payment\PaymentManager;
use support\Authcode;
use support\Response;
use Throwable;

/**
 * 商户自助添加与管理自己的收款账号/通道 API 控制器
 */
class MerchantChannelController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    /**
     * 商户获取自己绑定的所有收款账号
     */
    public function list(object $request): Response
    {
        $pid = $request->get('pid') ?? '';
        $merchant = Merchant::where('pid', $pid)->first();

        if (!$merchant) {
            return json(['code' => -1, 'msg' => '商户不存在']);
        }

        // 查询属于该商户(merchant_id)绑定的通道列表
        $channels = Channel::where('merchant_id', $merchant->id)->orderBy('id', 'desc')->get();

        return json(['code' => 1, 'data' => $channels]);
    }

    /**
     * 商户自助添加 / 绑定新的收款账号/通道
     */
    public function save(object $request): Response
    {
        try {
            $params = $request->post();
            $pid    = $params['pid'] ?? '';

            $merchant = Merchant::where('pid', $pid)->first();
            if (!$merchant) {
                return json(['code' => -1, 'msg' => '商户不存在']);
            }

            $title     = $params['title'] ?? '我的收款账号';
            $cType     = $params['c_type'] ?? 'alipay_app_asst';
            $rawConfig = $params['config'] ?? [];

            // 加密密钥信息
            $encryptedConfig = [];
            foreach ($rawConfig as $k => $v) {
                $encryptedConfig[$k] = is_string($v) ? $this->authcode->encrypt($v) : $v;
            }

            $channel = Channel::create([
                'merchant_id' => $merchant->id,
                'title'       => $title,
                'c_type'      => $cType,
                'config'      => json_encode($encryptedConfig, JSON_UNESCAPED_UNICODE),
                'weight'      => 50,
                'status'      => 1,
            ]);

            return json(['code' => 1, 'msg' => '绑定收款账号成功！', 'id' => $channel->id]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '绑定失败: ' . $e->getMessage()]);
        }
    }
}
