<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Packvip;
use support\Response;

/**
 * 管理员后台 VIP 套餐配置 API 控制器
 */
class PackvipAdminController
{
    /**
     * VIP 套餐列表
     */
    public function list(): string
    {
        $vips = Packvip::orderBy('weigh', 'desc')->get();
        return json_encode([
            'code' => 1,
            'msg' => '仅返回历史套餐配置；套餐购买与费率应用链路尚未接入',
            'data' => $vips,
            'active' => false,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 新增 / 保存 VIP 套餐
     */
    public function save(\support\Request $request): Response
    {
        return json([
            'code' => -1,
            'msg' => '套餐购买、续期与费率应用链路尚未完成，套餐配置写入已禁用',
        ])->withStatus(501);
    }
}
