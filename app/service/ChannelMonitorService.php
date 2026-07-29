<?php

declare(strict_types=1);

namespace app\service;

use app\model\Channel;
use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\ServerPollingDriverInterface;
use app\payment\PaymentManager;
use support\Authcode;

/**
 * 自动化通道巡检与订单超时自动关闭服务 (后台 Worker 定时调度)
 */
class ChannelMonitorService
{
    private OrderService $orderService;
    private Authcode $authcode;
    private CallbillService $callbillService;

    public function __construct()
    {
        $this->orderService = new OrderService();
        $this->authcode = new Authcode();
        $this->callbillService = new CallbillService();
    }

    /**
     * 巡检过期未支付订单并自动关闭
     */
    public function checkExpiredOrders(): int
    {
        return $this->orderService->expirePendingOrders();
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
            $cType = (string)$channel->c_type;
            if (!PaymentManager::has($cType)) {
                if ((int)$channel->online_status !== 0) {
                    $channel->online_status = 0;
                    $channel->save();
                }
                $offlineCount++;
                continue;
            }

            $monitorMode = PaymentManager::monitorMode($cType);
            $requiresHeartbeat = $monitorMode === MonitorableDriverInterface::MODE_PUSH;
            if ($monitorMode === MonitorableDriverInterface::MODE_SERVER) {
                // 服务端轮询通道的在线状态由真实查询结果维护，不能在心跳巡检中无条件置为在线。
                (int)$channel->status === 1 && (int)$channel->online_status === 1
                    ? $onlineCount++
                    : $offlineCount++;
                continue;
            }
            if (!$requiresHeartbeat) {
                if ((int)$channel->status === 1 && (int)$channel->online_status !== 1) {
                    $channel->online_status = 1;
                    $channel->save();
                }
                (int)$channel->status === 1 ? $onlineCount++ : $offlineCount++;
                continue;
            }

            $lastHeartbeat = (int)($channel->last_heartbeat_time ?? 0);

            if ((int)$channel->status === 1) {
                // 如果开启在线状态但长时间没有心跳，自动切断降级
                if ($lastHeartbeat <= 0 || ($now - $lastHeartbeat) > $timeoutThreshold) {
                    $channel->online_status = 0;
                    $channel->save();
                    $offlineCount++;
                    $autoOfflined++;
                } else {
                    if ((int)$channel->online_status !== 1) {
                        $channel->online_status = 1;
                        $channel->save();
                    }
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
     * 轮询明确声明 MODE_SERVER 的通道。
     *
     * 每次重叠读取最近十分钟，依靠稳定账单号做幂等。这样进程短暂重启不会形成账单盲区，
     * 同时不需要把游标写回加密通道配置。
     */
    public function pollServerChannels(): array
    {
        $stats = ['channels' => 0, 'events' => 0, 'errors' => 0];
        $until = time();
        // 十分钟重叠窗口可覆盖短时网络故障或 Worker 重启；账单稳定流水号负责去重。
        $since = $until - 600;

        foreach (Channel::where('status', 1)->get() as $channel) {
            $cType = (string)$channel->c_type;
            try {
                if (!PaymentManager::has($cType)
                    || PaymentManager::monitorMode($cType) !== MonitorableDriverInterface::MODE_SERVER) {
                    continue;
                }
                $driver = PaymentManager::make($cType);
                if (!$driver instanceof ServerPollingDriverInterface) {
                    throw new \RuntimeException('服务端监控驱动没有实现账单轮询契约');
                }

                $config = [];
                foreach (json_decode((string)$channel->config, true) ?: [] as $key => $value) {
                    $config[$key] = is_string($value) ? $this->authcode->decryptStored($value) : $value;
                }
                $events = array_slice($driver->pollPaymentEvents($config, $since, $until), 0, 100);
                $stats['channels']++;
                foreach ($events as $event) {
                    $billId = trim((string)($event['source_bill_id'] ?? ''));
                    $amount = trim((string)($event['amount'] ?? ''));
                    $occurredAt = (int)($event['occurred_at'] ?? 0);
                    if ($billId === '' || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)
                        || (float)$amount <= 0 || $occurredAt <= 0) {
                        throw new \RuntimeException('服务端监控驱动返回了不合法的账单事件');
                    }
                    $this->callbillService->processPush(
                        $cType,
                        'server-poller',
                        (float)$amount,
                        mb_substr((string)($event['remark'] ?? ''), 0, 255),
                        (int)$channel->id,
                        $billId,
                        $occurredAt,
                        (string)($event['raw_hash'] ?? ''),
                        'server-poller/1.0'
                    );
                    $stats['events']++;
                }
                if ((int)$channel->online_status !== 1) {
                    $channel->online_status = 1;
                    $channel->save();
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                if ((int)$channel->online_status !== 0) {
                    $channel->online_status = 0;
                    $channel->save();
                }
                error_log("[ChannelMonitor] poll {$cType}#{$channel->id}: " . $e->getMessage());
            }
        }

        return $stats;
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
