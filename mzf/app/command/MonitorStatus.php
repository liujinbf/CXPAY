<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Cache;

/**
 * 监控 worker 健康检查
 *
 * 用法：php think monitor:status
 * 读取 Redis 心跳 monitor:heartbeat，判断常驻 worker 是否存活。
 * 退出码 0=存活，1=无心跳/超时（可供外部探针/宝塔监控告警）。
 */
class MonitorStatus extends Command
{
    protected function configure(): void
    {
        $this->setName('monitor:status')->setDescription('检查常驻监控 worker 心跳是否存活');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $redis = Cache::store('redis')->handler();
            $beat  = $redis->get('monitor:heartbeat');
            $lock  = $redis->get('lock:monitor:instance');
        } catch (\Throwable $e) {
            $output->error('Redis 不可用：' . $e->getMessage());
            return 1;
        }

        if (!$beat) {
            $output->error('[monitor] 无心跳：worker 未运行或已停止');
            return 1;
        }

        $age = time() - (int) $beat;
        $msg = sprintf('[monitor] 最近心跳 %ds 前（%s）持有者=%s', $age, date('Y-m-d H:i:s', (int) $beat), $lock ?: '无');
        if ($age > 30) {
            $output->error($msg . ' —— 心跳超时(>30s)，worker 可能已卡死');
            return 1;
        }
        $output->info($msg . ' —— 存活');
        return 0;
    }
}
