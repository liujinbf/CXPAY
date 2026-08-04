<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Plan;
use app\payment\RemovedPaymentDrivers;
use support\Request;
use support\Response;

/**
 * 管理员后台套餐配置 API 控制器
 */
class PackvipAdminController
{
    /**
     * 套餐列表
     */
    public function list(): Response
    {
        $plans = Plan::orderBy('sort_order', 'asc')->orderBy('id', 'desc')->get();
        return json([
            'code' => 1,
            'msg'  => 'ok',
            'data' => $plans,
        ]);
    }

    /**
     * 新增 / 保存套餐
     */
    public function save(Request $request): Response
    {
        $id = (int)$request->post('id', 0);
        $name = trim((string)$request->post('name', ''));
        if ($name === '') {
            return json(['code' => -1, 'msg' => '请输入套餐名称']);
        }

        $days          = max(0, (int)$request->post('days', 30));
        $rate          = max(0.00, (float)$request->post('rate', 2.50));
        $minRate       = max(0.00, (float)$request->post('min_rate', 0.00));
        $channelQuota  = max(0, (int)$request->post('channel_quota', 0));

        $submittedAllowed = $request->post('allowed_channels');
        $allowedList = is_array($submittedAllowed)
            ? $submittedAllowed
            : explode(',', (string)$submittedAllowed);
        $allowedList = array_values(array_filter(
            array_map(
                static fn($value): string => trim((string)$value),
                $allowedList
            ),
            static fn(string $value): bool => $value !== ''
        ));

        $removed = array_values(array_filter(
            $allowedList,
            static fn(string $code): bool =>
                RemovedPaymentDrivers::contains($code)
        ));
        if ($removed !== []) {
            return json([
                'code' => -1,
                'msg' => '套餐包含已永久移除的支付驱动：'
                    . implode(', ', $removed),
            ]);
        }

        $allowedCh = implode(',', $allowedList);
        $price         = max(0.00, (float)$request->post('price', 0.00));
        $limitCount    = max(0, (int)$request->post('limit_count', 0));
        $memo          = trim((string)$request->post('memo', ''));
        $sortOrder     = (int)$request->post('sort_order', 0);
        $status        = (int)$request->post('status', 1) === 1 ? 1 : 0;

        $data = [
            'name'             => $name,
            'days'             => $days,
            'rate'             => number_format($rate, 2, '.', ''),
            'min_rate'         => number_format($minRate, 2, '.', ''),
            'channel_quota'    => $channelQuota,
            'allowed_channels' => $allowedCh,
            'price'            => number_format($price, 2, '.', ''),
            'limit_count'      => $limitCount,
            'memo'             => $memo,
            'sort_order'       => $sortOrder,
            'status'           => $status,
        ];

        if ($id > 0) {
            $plan = Plan::find($id);
            if (!$plan) {
                return json(['code' => -1, 'msg' => '套餐不存在']);
            }
            $plan->update($data);
            $msg = '套餐更新成功';
        } else {
            $data['create_time'] = time();
            Plan::create($data);
            $msg = '套餐创建成功';
        }

        return json(['code' => 1, 'msg' => $msg]);
    }

    /**
     * 删除套餐
     */
    public function delete(Request $request): Response
    {
        $id = (int)$request->post('id', 0);
        $plan = Plan::find($id);
        if (!$plan) {
            return json(['code' => -1, 'msg' => '套餐不存在']);
        }
        $plan->delete();
        return json(['code' => 1, 'msg' => '套餐已删除']);
    }
}
