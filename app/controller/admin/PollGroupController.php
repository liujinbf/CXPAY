<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\PollGroup;
use app\model\PollGroupChannel;
use app\model\Channel;
use support\Response;
use Exception;

/**
 * 管理员后台轮询组管理 API 控制器
 */
class PollGroupController
{
    /**
     * 获取轮询组列表
     */
    public function list(object $request): string
    {
        $groups = PollGroup::all();
        return json_encode(['code' => 1, 'data' => $groups], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 新增 / 修改轮询组
     */
    public function save(object $request): string
    {
        $params = $request->post();
        $id     = $params['id'] ?? null;

        $data = [
            'name'   => $params['name'] ?? '轮询分组',
            'c_type' => $params['c_type'] ?? 'alipay',
            'status' => (int)($params['status'] ?? 1),
        ];

        if ($id) {
            PollGroup::where('id', $id)->update($data);
            $msg = '轮询组更新成功';
        } else {
            PollGroup::create($data);
            $msg = '新轮询组创建成功';
        }

        return json_encode(['code' => 1, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 为轮询组绑定通道及权重
     */
    public function bindChannel(object $request): string
    {
        $params    = $request->post();
        $groupId   = (int)($params['group_id'] ?? 0);
        $channelId = (int)($params['channel_id'] ?? 0);
        $weight    = (int)($params['weight'] ?? 50);

        PollGroupChannel::create([
            'group_id'   => $groupId,
            'channel_id' => $channelId,
            'weight'     => $weight,
        ]);

        return json_encode(['code' => 1, 'msg' => '通道权重绑定成功'], JSON_UNESCAPED_UNICODE);
    }
}
