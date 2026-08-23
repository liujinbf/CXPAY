<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Channel;
use app\model\PollGroup;
use app\model\PollGroupChannel;
use app\service\PollService;
use support\Db;
use support\Request;
use support\Response;
use Throwable;

/**
 * 管理员后台轮询组管理 API 控制器
 */
class PollGroupController
{
    /**
     * 获取轮询组列表及绑定的通道明细
     */
    public function list(Request $request): Response
    {
        try {
            $groups = PollGroup::orderBy('id', 'desc')->get();
            $data = [];

            foreach ($groups as $group) {
                $groupChannels = PollGroupChannel::where('group_id', $group->id)
                    ->with('channel')
                    ->get();

                $channelsList = [];
                $onlineCount = 0;
                $totalWeight = 0;

                foreach ($groupChannels as $item) {
                    $channel = $item->channel;
                    if ($channel) {
                        $isOnline = ((int)$channel->status === 1 && (int)$channel->online_status === 1);
                        if ($isOnline) {
                            $onlineCount++;
                        }
                        $totalWeight += (int)$item->weight;
                        $channelsList[] = [
                            'id'            => $channel->id,
                            'title'         => $channel->title,
                            'c_type'        => $channel->c_type,
                            'pay_category'  => $channel->pay_category,
                            'status'        => (int)$channel->status,
                            'online_status' => (int)$channel->online_status,
                            'weight'        => (int)$item->weight,
                            'today_money'   => (string)$channel->today_money,
                            'single_min'    => (string)$channel->single_min,
                            'single_max'    => (string)$channel->single_max,
                            'day_max'       => (string)$channel->day_max,
                        ];
                    }
                }

                $data[] = [
                    'id'                    => $group->id,
                    'name'                  => $group->name,
                    'c_type'                => $group->c_type,
                    'strategy'              => (int)($group->strategy ?? 1),
                    'status'                => (int)($group->status ?? 1),
                    'merchant_id'           => (int)($group->merchant_id ?? 0),
                    'create_time'           => (int)($group->create_time ?? 0),
                    'update_time'           => (int)($group->update_time ?? 0),
                    'channels_count'        => count($channelsList),
                    'online_channels_count' => $onlineCount,
                    'total_weight'          => $totalWeight,
                    'channels'              => $channelsList,
                ];
            }

            return json([
                'code'   => 1,
                'msg'    => '获取轮询组成功',
                'data'   => $data,
                'active' => true,
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '获取轮询组失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 新增 / 编辑轮询组基础信息
     */
    public function save(Request $request): Response
    {
        $id       = (int)($request->post('id') ?? 0);
        $name     = trim((string)$request->post('name', ''));
        $cType    = trim((string)$request->post('c_type', 'wxpay'));
        $strategy = (int)($request->post('strategy', 1));
        $status   = (int)($request->post('status', 1));

        if ($name === '') {
            return json(['code' => -1, 'msg' => '轮询组名称不能为空']);
        }
        if (!in_array($cType, ['wxpay', 'alipay', 'qqpay', 'usdt'], true)) {
            return json(['code' => -1, 'msg' => '不支持的支付分类']);
        }
        if (!in_array($strategy, [1, 2, 3], true)) {
            $strategy = 1;
        }

        try {
            if ($id > 0) {
                $group = PollGroup::find($id);
                if (!$group) {
                    return json(['code' => -1, 'msg' => '轮询组不存在或已被删除']);
                }
                $group->name        = $name;
                $group->c_type      = $cType;
                $group->strategy    = $strategy;
                $group->status      = $status;
                $group->update_time = time();
                $group->save();
            } else {
                $group = PollGroup::create([
                    'name'        => $name,
                    'c_type'      => $cType,
                    'strategy'    => $strategy,
                    'merchant_id' => 0,
                    'status'      => $status,
                    'create_time' => time(),
                    'update_time' => time(),
                ]);
            }

            return json([
                'code' => 1,
                'msg'  => '🎉 轮询组保存成功！',
                'data' => $group,
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '保存失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 快捷切换轮询组启用/停用状态
     */
    public function toggle(Request $request): Response
    {
        $id     = (int)($request->post('id') ?? 0);
        $status = (int)($request->post('status') ?? 0);

        if ($id <= 0) {
            return json(['code' => -1, 'msg' => '缺少轮询组ID']);
        }

        try {
            $group = PollGroup::find($id);
            if (!$group) {
                return json(['code' => -1, 'msg' => '轮询组不存在']);
            }
            $group->status = $status ? 1 : 0;
            $group->update_time = time();
            $group->save();

            return json([
                'code' => 1,
                'msg'  => $status ? '轮询组已启用' : '轮询组已停用',
                'data' => ['status' => $group->status],
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 删除轮询组
     */
    public function delete(Request $request): Response
    {
        $id = (int)($request->post('id') ?? $request->get('id') ?? 0);
        if ($id <= 0) {
            return json(['code' => -1, 'msg' => '缺少轮询组ID']);
        }

        try {
            Db::transaction(function () use ($id) {
                PollGroupChannel::where('group_id', $id)->delete();
                PollGroup::where('id', $id)->delete();
            });

            return json(['code' => 1, 'msg' => '轮询组已删除！']);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 获取指定分类下所有可供绑定的支付通道
     */
    public function availableChannels(Request $request): Response
    {
        $cType = trim((string)($request->get('c_type') ?? 'wxpay'));

        try {
            $channels = Channel::where('status', 1)
                ->where(function ($query) use ($cType) {
                    $query->where('pay_category', $cType)
                        ->orWhere('c_type', 'LIKE', $cType . '%');
                })
                ->orderBy('id', 'desc')
                ->get(['id', 'title', 'c_type', 'pay_category', 'status', 'online_status', 'weight', 'today_money']);

            return json([
                'code' => 1,
                'data' => $channels,
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '获取可用通道失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 批量绑定通道及设置权重
     */
    public function bindChannels(Request $request): Response
    {
        $groupId  = (int)($request->post('group_id') ?? 0);
        $rawItems = $request->post('channels');

        if ($groupId <= 0) {
            return json(['code' => -1, 'msg' => '缺少轮询组ID']);
        }

        $items = is_string($rawItems) ? json_decode($rawItems, true) : $rawItems;
        if (!is_array($items)) {
            $items = [];
        }

        try {
            Db::transaction(function () use ($groupId, $items) {
                PollGroupChannel::where('group_id', $groupId)->delete();

                foreach ($items as $item) {
                    $channelId = (int)($item['channel_id'] ?? $item['id'] ?? 0);
                    $weight    = max(1, min(1000, (int)($item['weight'] ?? 50)));
                    if ($channelId > 0) {
                        PollGroupChannel::create([
                            'group_id'    => $groupId,
                            'channel_id'  => $channelId,
                            'weight'      => $weight,
                            'create_time' => time(),
                        ]);
                    }
                }

                PollGroup::where('id', $groupId)->update(['update_time' => time()]);
            });

            return json([
                'code' => 1,
                'msg'  => '🎉 通道绑定与权重配置已成功生效！',
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '绑定通道失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 模拟测试调度通道
     */
    public function simulate(Request $request): Response
    {
        $cType  = trim((string)($request->post('c_type') ?? 'wxpay'));
        $amount = (float)($request->post('amount') ?? 10.00);

        try {
            $pollService = new PollService();
            $result = $pollService->selectChannel(0, $cType, $amount);

            $channel = Channel::find($result['channel_id']);

            return json([
                'code' => 1,
                'msg'  => '调度测试成功',
                'data' => [
                    'selected_channel_id'   => $result['channel_id'],
                    'selected_channel_title'=> $channel?->title ?? "通道 #{$result['channel_id']}",
                    'c_type'                => $result['c_type'],
                    'poll_group_id'         => $result['poll_group_id'],
                    'amount'                => $amount,
                ],
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '调度测试未命中通道: ' . $e->getMessage()]);
        }
    }
}
