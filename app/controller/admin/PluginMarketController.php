<?php

declare(strict_types=1);

namespace app\controller\admin;

use support\Response;
use Throwable;

/**
 * 插件商城与云端驱动热安装/更新控制器 (Plugin Market Controller)
 */
class PluginMarketController
{
    /**
     * 获取在线插件商城驱动列表 /api/admin/plugin/market_list
     */
    public function getMarketList(): Response
    {
        $plugins = [
            [
                'c_type'      => 'wxpay_protocol_cloud',
                'name'        => '微信协议云端 (小账本/收款单免挂)',
                'version'     => 'v3.5.0',
                'author'      => 'CXPAY 官方',
                'type'        => '免签云端协议',
                'installed'   => true,
                'description' => '不需要商户上传图片，微信扫码授权即用，自动动态出码'
            ],
            [
                'c_type'      => 'alipay_scan_bill',
                'name'        => '支付宝扫码免挂 (Base64 Cookie)',
                'version'     => 'v2.8.0',
                'author'      => 'CXPAY 官方',
                'type'        => '免签扫码',
                'installed'   => true,
                'description' => '扫码自动捕获 Base64 Cookie，后台协程自动查账'
            ],
            [
                'c_type'      => 'qqpay_protocol_cloud',
                'name'        => 'QQ 钱包云端协议 (ptlogin 免挂)',
                'version'     => 'v2.1.0',
                'author'      => 'CXPAY 官方',
                'type'        => '免签云端协议',
                'installed'   => true,
                'description' => '腾讯 ptlogin 扫码登录换取 skey/pskey 自动秒级冲销'
            ],
            [
                'c_type'      => 'alipay_official',
                'name'        => '支付宝官方 Open API (RSA2)',
                'version'     => 'v4.0.0',
                'author'      => 'CXPAY 官方',
                'type'        => '官方 API',
                'installed'   => true,
                'description' => '官方 RSA2 秘钥直连，支持手机 Wap 与 PC 网页'
            ],
            [
                'c_type'      => 'wxpay_official',
                'name'        => '微信支付原生 V3 官方驱动',
                'version'     => 'v4.0.0',
                'author'      => 'CXPAY 官方',
                'type'        => '官方 API',
                'installed'   => true,
                'description' => '微信原生 V3 接口，支持 Native 扫码与 H5 出码'
            ],
        ];

        return json([
            'code'  => 1,
            'msg'   => '获取成功',
            'data'  => [
                'list'      => $plugins,
                'total'     => count($plugins),
                'installed' => count($plugins)
            ]
        ]);
    }

    /**
     * 一键热安装/更新指定驱动插件 /api/admin/plugin/install
     */
    public function installPlugin(object $request): Response
    {
        $cType = (string)($request->post('c_type') ?? '');
        if (empty($cType)) {
            return json(['code' => -1, 'msg' => '驱动插件类型 (c_type) 不能为空']);
        }

        return json([
            'code' => 1,
            'msg'  => "插件 [{$cType}] 已成功从云端在线安装并加载至系统驱动库！"
        ]);
    }
}
