<?php

declare(strict_types=1);

namespace app\controller\api;

use app\model\Channel;
use app\model\Merchant;
use support\Authcode;
use support\Response;
use Throwable;

/**
 * 商户自助添加、编辑与管理个人收款账号/通道 API 控制器
 */
class MerchantChannelController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    /**
     * 商户获取自己绑定的所有收款账号列表
     */
    public function list(object $request): Response
    {
        $pid = $request->get('pid') ?? $request->post('pid') ?? '1000';
        $merchant = Merchant::where('pid', $pid)->first();

        if (!$merchant) {
            // 兼容缺省体验商户
            $merchant = Merchant::first();
        }

        $merchantId = $merchant ? $merchant->id : 1000;

        // 查询属于该商户绑定的收款通道
        $channels = Channel::where('merchant_id', $merchantId)->orderBy('id', 'desc')->get();

        // 若无记录，初始化 2 条示例通道供演示
        if ($channels->isEmpty()) {
            $demo1 = Channel::create([
                'merchant_id'   => $merchantId,
                'pay_category'  => 'alipay',
                'title'         => '支付宝扫码免挂',
                'c_type'        => 'alipay_scan',
                'remark'        => '支付宝个人码 #15697116375',
                'config'        => '{}',
                'today_money'   => 680.00,
                'today_count'   => 15,
                'total_money'   => 12450.00,
                'online_status' => 1,
                'status'        => 1,
            ]);
            $demo2 = Channel::create([
                'merchant_id'   => $merchantId,
                'pay_category'  => 'wxpay',
                'title'         => '微信协议云端-[个人动态码]',
                'c_type'        => 'wxpay_protocol_cloud',
                'remark'        => '微信店员小账本免挂 #WX678',
                'config'        => '{}',
                'today_money'   => 600.50,
                'today_count'   => 27,
                'total_money'   => 8920.00,
                'online_status' => 1,
                'status'        => 1,
            ]);
            $channels = collect([$demo1, $demo2]);
        }

        return json(['code' => 1, 'data' => $channels]);
    }

    /**
     * 商户自助添加 / 编辑保存收款账号/通道 (数据库落库)
     */
    public function save(object $request): Response
    {
        try {
            $params = $request->post();
            $pid    = $params['pid'] ?? '1000';
            $id     = (int)($params['id'] ?? 0);

            $merchant = Merchant::where('pid', $pid)->first();
            if (!$merchant) {
                $merchantId = 1000;
            } else {
                $merchantId = $merchant->id;
            }

            $payCategory = trim((string)($params['pay_category'] ?? 'wxpay'));
            $cType       = trim((string)($params['c_type'] ?? 'wxpay_protocol_cloud'));
            $title       = trim((string)($params['title'] ?? ''));
            $remark      = trim((string)($params['remark'] ?? ''));
            $status      = (int)($params['status'] ?? 1);

            if (empty($title)) {
                $title = $cType;
            }

            $data = [
                'merchant_id'   => $merchantId,
                'pay_category'  => $payCategory,
                'title'         => $title,
                'c_type'        => $cType,
                'remark'        => $remark,
                'status'        => $status,
                'online_status' => 1,
            ];

            if ($id > 0) {
                Channel::where('id', $id)->update($data);
                $msg = '编辑收款通道成功！';
                $channelId = $id;
            } else {
                $data['today_money'] = 0.00;
                $data['today_count'] = 0;
                $data['total_money'] = 0.00;
                $channel = Channel::create($data);
                $msg = '新增收款通道保存成功！';
                $channelId = $channel->id;
            }

            return json(['code' => 1, 'msg' => $msg, 'id' => $channelId]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '保存通道失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 切换通道状态开关 (开启/禁用)
     */
    public function toggle(object $request): Response
    {
        try {
            $params = $request->post();
            $id     = (int)($params['id'] ?? 0);
            $status = (int)($params['status'] ?? 1);

            Channel::where('id', $id)->update(['status' => $status]);
            return json(['code' => 1, 'msg' => '通道状态更新成功！']);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '状态修改失败']);
        }
    }

    /**
     * 删除收款通道
     */
    public function delete(object $request): Response
    {
        try {
            $params = $request->post();
            $id     = (int)($params['id'] ?? 0);

            Channel::where('id', $id)->delete();
            return json(['code' => 1, 'msg' => '通道删除成功！']);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '删除失败']);
        }
    }

    /**
     * 获取商户套餐允许开通的驱动列表
     */
    public function drivers(object $request): Response
    {
        $drivers = [
            'wxpay' => [
                ['name' => '微信协议云端-[个人动态码]', 'c_type' => 'wxpay_protocol_cloud'],
                ['name' => 'PC小账本', 'c_type' => 'wxpay_recpt_afk_pc'],
                ['name' => '安卓APP监控助手', 'c_type' => 'wxpay_app_asst'],
                ['name' => 'iPad免挂-小账本[个人动态码]', 'c_type' => 'wxpay_ipad_cloud'],
                ['name' => '易支付-微信网关', 'c_type' => 'epay_generic_wx'],
                ['name' => '官方iPad免挂', 'c_type' => 'wxpay_official_ipad'],
            ],
            'alipay' => [
                ['name' => '支付宝扫码免挂', 'c_type' => 'alipay_scan'],
                ['name' => '支付宝APP监控助手', 'c_type' => 'alipay_app_asst'],
                ['name' => '支付宝官方原生扫码', 'c_type' => 'alipay_official'],
                ['name' => '易支付-支付宝网关', 'c_type' => 'epay_generic_ali'],
            ],
            'qqpay' => [
                ['name' => 'QQ钱包 APP 助手挂机', 'c_type' => 'qqpay_app_asst'],
                ['name' => 'QQ ptlogin 云端免挂', 'c_type' => 'qqpay_protocol_cloud'],
                ['name' => '易支付-QQ钱包网关', 'c_type' => 'epay_generic_qq'],
            ]
        ];

        return json(['code' => 1, 'data' => $drivers]);
    }
}
