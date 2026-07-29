<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\PollGroup;
use support\Response;

/**
 * 管理员后台轮询组管理 API 控制器
 */
class PollGroupController
{
    /**
     * 获取轮询组列表
     */
    public function list(\support\Request $request): string
    {
        $groups = PollGroup::all();
        return json_encode([
            'code' => 1,
            'msg' => '仅返回历史配置；轮询组尚未接入当前通道调度器',
            'data' => $groups,
            'active' => false,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 新增 / 修改轮询组
     */
    public function save(\support\Request $request): Response
    {
        return response(
            json_encode(['code' => -1, 'msg' => '轮询组尚未接入通道调度器，配置写入已禁用'], JSON_UNESCAPED_UNICODE),
            501,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * 为轮询组绑定通道及权重
     */
    public function bindChannel(\support\Request $request): Response
    {
        return response(
            json_encode(['code' => -1, 'msg' => '轮询组尚未接入通道调度器，通道绑定已禁用'], JSON_UNESCAPED_UNICODE),
            501,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }
}
