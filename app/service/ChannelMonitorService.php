<?php

declare(strict_types=1);

namespace app\service;

use app\model\Channel;
use app\model\Order;
use Exception;

/**
 * 自动化通道巡检与订单超时自动关闭服务 (后台 Worker 定时调度)
 */
class ChannelMonitorService
{
    /**
     * 巡检过期未支付订单并自动关闭
     */
    public function checkExpiredOrders(): int
    {
        $now = time();
        $expiredCount = Order::where('status', 0)
            ->where('expire_time', '>', 0)
            ->where('expire_time', '<=', $now)
            ->update(['status' => 2]); // status=2 代表已超时关闭

        return $expiredCount;
    }

    /**
     * 检查通道心跳与在线状态 (超时 60 秒无心跳自动切为离线休眠状态)
     */
    public function checkChannelHeartbeats(): array
    {
        $channels = Channel::all();
        $now = time();
        $timeoutThreshold = 60; // 60秒无心跳判为离线

        $onlineCount  = 0;
        $offlineCount = 0;
        $autoOfflined = 0;

        foreach ($channels as $channel) {
            $lastHeartbeat = (int)($channel->last_heartbeat_time ?? 0);

            if ((int)$channel->status === 1) {
                // 如果开启在线状态但长时间没有心跳，自动切断降级
                if ($lastHeartbeat > 0 && ($now - $lastHeartbeat) > $timeoutThreshold) {
                    $channel->status = 0;
                    $channel->save();
                    $offlineCount++;
                    $autoOfflined++;
                } else {
                    $onlineCount++;
                }
            } else {
                $offlineCount++;
            }
        }

        return [
            'total'        => count($channels),
            'online'       => $onlineCount,
            'offline'      => $offlineCount,
            'auto_offlined' => $autoOfflined,
        ];
    }
    /**
     * 每日凌晨重置通道当日统计（today_money / today_count）
     *
     * 修复：today_money 无自动重置机制，导致日限额检查在跨日后永久失效
     * （通道被 PollService 永久过滤，新流量无法进入）
     *
     * 调用方式：在 config/process.php 中配置 WorkerMan Timer，每日 00:00 调用
     *
     * @return int 重置的通道数量
     */
    public function resetDailyStats(): int
    {
        return Channel::query()->update([
            'today_money' => 0,
            'today_count' => 0,
        ]);
    }
}
