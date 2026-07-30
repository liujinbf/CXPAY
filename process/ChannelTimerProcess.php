<?php

declare(strict_types=1);

namespace process;

use app\service\ChannelMonitorService;
use app\service\MerchantNotifyService;
use app\service\AlertNotificationService;
use Workerman\Timer;

/**
 * 通道定时维护进程
 */
class ChannelTimerProcess
{
    protected ChannelMonitorService $monitorService;
    protected MerchantNotifyService $notifyService;
    protected AlertNotificationService $alertService;

    public function onWorkerStart(): void
    {
        // 系统未安装时跳过所有定时任务，避免在无 DB/Redis 配置环境下
        // 实例化服务导致 socket 连接失败，进而触发 Workerman stream 异常崩溃。
        $lockFile = (string)config('app.install_lock', base_path() . '/install.lock');
        if (!file_exists($lockFile)) {
            return;
        }

        $this->monitorService = new ChannelMonitorService();
        $this->notifyService  = new MerchantNotifyService();
        $this->alertService   = new AlertNotificationService();

        Timer::add(30, function () {
            try {
                $this->monitorService->checkExpiredOrders();
                $this->monitorService->checkChannelHeartbeats();
            } catch (\Throwable $e) {
                error_log('[ChannelTimer] periodic maintenance: ' . $e->getMessage());
            }
        });

        Timer::add(5, function () {
            try {
                $this->notifyService->processRetryQueue();
                $this->alertService->processQueue();
            } catch (\Throwable $e) {
                error_log('[ChannelTimer] processRetryQueue/alertQueue: ' . $e->getMessage());
            }
        });

        Timer::add(15, function () {
            try {
                $this->monitorService->pollServerChannels();
            } catch (\Throwable $e) {
                error_log('[ChannelTimer] pollServerChannels: ' . $e->getMessage());
            }
        });

        $this->scheduleDailyReset();
    }

    private function scheduleDailyReset(): void
    {
        $now  = time();
        $next = mktime(0, 0, 0, (int)date('m', $now), (int)date('d', $now) + 1, (int)date('Y', $now));
        Timer::add($next - $now, function () {
            try {
                $c = $this->monitorService->resetDailyStats();
                error_log('[ChannelTimer] Daily stats reset, affected: ' . $c);
            } catch (\Throwable $e) {
                error_log('[ChannelTimer] resetDailyStats: ' . $e->getMessage());
            }
            $this->scheduleDailyReset();
        }, null, false);
    }
}
