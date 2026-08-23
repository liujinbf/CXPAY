<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Plan;
use app\payment\PaymentManager;
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
     * 获取管理端已安装且可用的支付驱动与分类列表（供套餐绑定多选）
     */
    public function drivers(): Response
    {
        PaymentManager::flush();
        $drivers = PaymentManager::getRegisteredDrivers();
        $list = [];
        $seen = [];

        // 1. 支付大类通配选项（方便管理员直接按大类或全选授权）
        $list[] = [
            'c_type'      => '*',
            'name'        => '全量支付通道驱动 (All Drivers)',
            'category'    => 'all',
            'is_category' => true,
            'description' => '授权商户使用系统当前及未来新上架的所有支付插件与驱动',
        ];
        $list[] = [
            'c_type'      => 'wxpay',
            'name'        => '微信支付全系 (包含个人免挂/店员小账本/PC挂机)',
            'category'    => 'wxpay',
            'is_category' => true,
            'description' => '授权所有微信支付相关通道',
        ];
        $list[] = [
            'c_type'      => 'alipay',
            'name'        => '支付宝全系 (包含免挂助手/当面付/官方直连)',
            'category'    => 'alipay',
            'is_category' => true,
            'description' => '授权所有支付宝相关通道',
        ];
        $list[] = [
            'c_type'      => 'qqpay',
            'name'        => 'QQ钱包全系 (包含免挂助手/扫码转账)',
            'category'    => 'qqpay',
            'is_category' => true,
            'description' => '授权所有QQ钱包相关通道',
        ];

        // 2. 具体驱动项
        foreach ($drivers as $cType => $meta) {
            if (isset($seen[$cType]) || RemovedPaymentDrivers::contains($cType)) {
                continue;
            }
            $seen[$cType] = true;
            $category = $meta['category'] ?? $meta['pay_category'] ?? '';
            if ($category === '') {
                if (str_starts_with($cType, 'wechat_') || str_starts_with($cType, 'wx_') || str_starts_with($cType, 'wxpay_')) {
                    $category = 'wxpay';
                } elseif (str_starts_with($cType, 'alipay_') || str_starts_with($cType, 'ali_')) {
                    $category = 'alipay';
                } elseif (str_starts_with($cType, 'qqpay_') || str_starts_with($cType, 'qq_')) {
                    $category = 'qqpay';
                } else {
                    $category = 'other';
                }
            }

            $isEntitled = \app\service\PluginLicenseService::isChannelEntitled($cType);
            $list[] = [
                'c_type'      => $cType,
                'name'        => (string)($meta['title'] ?? $meta['name'] ?? $cType) . ($isEntitled ? ' (已开通)' : ' (未开通-插件市场购买)'),
                'category'    => $category,
                'is_category' => false,
                'is_entitled' => $isEntitled,
                'description' => (string)($meta['description'] ?? ''),
            ];
        }

        // 3. 动态合并云端商品库中已上架的扩展插件（未本地安装也能提前按需在套餐中配置授权）
        try {
            $cloudPlugins = \app\model\CloudPlugin::where('status', 1)->get();
            foreach ($cloudPlugins as $cp) {
                $cType = (string)($cp->c_type ?? '');
                if ($cType === '' || isset($seen[$cType]) || RemovedPaymentDrivers::contains($cType)) {
                    continue;
                }
                $seen[$cType] = true;
                $list[] = [
                    'c_type'      => $cType,
                    'name'        => (string)$cp->name,
                    'category'    => (string)($cp->category ?: 'other'),
                    'is_category' => false,
                    'description' => (string)($cp->description ?: ''),
                ];
            }
        } catch (\Throwable) {
            // 容错隔离
        }

        return json([
            'code' => 1,
            'msg'  => 'ok',
            'data' => $list,
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
