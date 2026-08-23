<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Channel;
use app\model\Order;
use app\service\ChannelMonitorService;
use support\Authcode;
use support\Request;
use support\Response;
use Throwable;

/**
 * 个人码/挂机助手/云端监控大盘管理控制器
 */
class CloudMonitorAdminController
{
    private Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    /**
     * 获取所有监控通道与采集设备的实时运行状态与流水统计
     */
    public function status(Request $request): Response
    {
        try {
            // 1. 同步刷新所有通道在线状态
            $monitorService = new ChannelMonitorService();
            $monitorService->checkChannelHeartbeats();

            // 2. 查询所有个人码/挂机助手/云监控类型的通道
            $monitorDrivers = [
                'cxpay.app_asst_universal',
                'wxpay_app_asst',
                'alipay_app_asst',
                'qqpay_app_asst',
                'alipay_cookie_cloud',
                'wechat_dy_bill',
            ];

            $channels = Channel::where(function ($query) use ($monitorDrivers) {
                $query->whereIn('c_type', $monitorDrivers)
                    ->orWhere('c_type', 'LIKE', '%app_asst%')
                    ->orWhere('c_type', 'LIKE', '%cookie%')
                    ->orWhere('c_type', 'LIKE', '%dy_bill%');
            })
            ->orderBy('status', 'desc')
            ->orderBy('online_status', 'desc')
            ->orderBy('id', 'desc')
            ->get();

            // 若无特定驱动通道，则回退查询所有通道
            if ($channels->isEmpty()) {
                $channels = Channel::orderBy('status', 'desc')->orderBy('id', 'desc')->get();
            }

            $list = [];
            $totalCount = 0;
            $onlineCount = 0;
            $offlineCount = 0;
            $todayTotalMoney = 0.00;
            $warnings = [];

            $now = time();

            foreach ($channels as $channel) {
                $totalCount++;
                $isOnline = ((int)$channel->status === 1 && (int)$channel->online_status === 1);
                if ($isOnline) {
                    $onlineCount++;
                } else {
                    $offlineCount++;
                }

                $todayMoney = (float)$channel->today_money;
                $todayTotalMoney += $todayMoney;

                $lastHeartbeat = (int)($channel->last_heartbeat_time ?? 0);
                $heartbeatDiff = $lastHeartbeat > 0 ? ($now - $lastHeartbeat) : -1;

                // 解密配置以提取 device_id 或 备注
                $rawConfig = json_decode((string)$channel->config, true) ?: [];
                $deviceId = (string)($rawConfig['device_id'] ?? $rawConfig['collector_id'] ?? $rawConfig['account_id'] ?? '');

                // 统计该通道今日订单数与成功率
                $todayStart = strtotime(date('Y-m-d 00:00:00'));
                $todayOrders = Order::where('channel_id', $channel->id)
                    ->where('create_time', '>=', $todayStart)
                    ->count();
                $todayPaidOrders = Order::where('channel_id', $channel->id)
                    ->where('create_time', '>=', $todayStart)
                    ->where('status', 1)
                    ->count();

                if ((int)$channel->status === 1 && !$isOnline) {
                    $warnings[] = "⚠️ 通道 #{$channel->id} ({$channel->title}) 处于启用状态但当前离线，已超过 120 秒未收到采集心跳！";
                }

                $list[] = [
                    'id'                  => (int)$channel->id,
                    'title'               => (string)$channel->title,
                    'c_type'              => (string)$channel->c_type,
                    'pay_category'        => (string)$channel->pay_category,
                    'merchant_id'         => (int)$channel->merchant_id,
                    'status'              => (int)$channel->status,
                    'online_status'       => (int)$channel->online_status,
                    'is_online'           => $isOnline,
                    'device_id'           => $deviceId,
                    'last_heartbeat_time' => $lastHeartbeat,
                    'heartbeat_diff'      => $heartbeatDiff,
                    'online_since'        => (int)($channel->online_since ?? 0),
                    'today_money'         => number_format($todayMoney, 2, '.', ''),
                    'single_min'          => (string)$channel->single_min,
                    'single_max'          => (string)$channel->single_max,
                    'day_max'             => (string)$channel->day_max,
                    'weight'              => (int)$channel->weight,
                    'today_orders'        => $todayOrders,
                    'today_paid_orders'   => $todayPaidOrders,
                ];
            }

            return json([
                'code' => 1,
                'msg'  => '获取云监控状态成功',
                'data' => [
                    'stats' => [
                        'total_devices'     => $totalCount,
                        'online_devices'    => $onlineCount,
                        'offline_devices'   => $offlineCount,
                        'today_total_money' => number_format($todayTotalMoney, 2, '.', ''),
                        'server_time'       => $now,
                    ],
                    'channels' => $list,
                    'warnings' => array_slice($warnings, 0, 5), // 最多展示 5 条主要告警
                ],
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => -1,
                'msg'  => '获取云监控状态失败: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * 快捷切换通道状态
     */
    public function toggle(Request $request): Response
    {
        $id     = (int)($request->post('id') ?? 0);
        $status = (int)($request->post('status') ?? 0);

        if ($id <= 0) {
            return json(['code' => -1, 'msg' => '缺少通道ID']);
        }

        try {
            $channel = Channel::find($id);
            if (!$channel) {
                return json(['code' => -1, 'msg' => '通道不存在']);
            }
            $channel->status = $status ? 1 : 0;
            $channel->save();

            return json([
                'code' => 1,
                'msg'  => $status ? '通道已启用' : '通道已停用',
                'data' => ['status' => $channel->status],
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '操作失败: ' . $e->getMessage()]);
        }
    }
}
