<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Cache;
use app\core\SettlementService;
use app\common\model\PayOrder;
use app\common\service\ChannelMonitorService;

/**
 * 常驻监控/结算 worker（Swoole 事件循环）
 *
 * 用法：php think monitor:run
 * 替代旧三套 crontab：通道在线监控/Cookie保活/到账轮询 + 订单超时 + 结算对账。
 * 生产用 systemd 或 宝塔进程守护 常驻运行（见 deploy/monitor-worker.md）。
 *
 * 生产加固：
 *  - 单实例锁(Redis lock:monitor:instance)：防止误开多个进程导致重复结算/巡检。
 *  - 优雅退出(SIGTERM/SIGINT)：清 Timer + 释放锁再退出，部署重启不丢单。
 *  - 心跳(Redis monitor:heartbeat)：供 monitor:status / 外部健康检查判活。
 */
class MonitorRun extends Command
{
    /** 单实例锁 key / 本进程持有令牌 / 心跳 key */
    protected string $lockKey  = 'lock:monitor:instance';
    protected string $beatKey  = 'monitor:heartbeat';
    protected string $token    = '';
    /** 锁 TTL（秒），每 5s 心跳续期 */
    protected int $lockTtl = 30;

    protected function configure(): void
    {
        $this->setName('monitor:run')->setDescription('常驻监控/结算 worker（通道监控+订单超时+结算对账）');
    }

    protected function execute(Input $input, Output $output): int
    {
        if (!extension_loaded('swoole')) {
            $output->error('未安装 swoole 扩展');
            return 1;
        }

        // 单实例保护：抢不到锁说明已有 worker 在跑，直接退出
        $this->token = $this->genToken();
        if (!$this->acquireInstanceLock()) {
            $output->error('已有 monitor worker 在运行（未抢到 ' . $this->lockKey . '），本进程退出');
            return 1;
        }

        $this->out($output, '[monitor] 常驻 worker 启动 @ ' . date('Y-m-d H:i:s') . ' token=' . $this->token);

        // 优雅退出：清 Timer + 释放锁
        $shutdown = function (int $sig) use ($output) {
            $this->out($output, '[monitor] 收到信号 ' . $sig . '，正在退出…');
            \Swoole\Timer::clearAll();
            $this->releaseInstanceLock();
            \Swoole\Event::exit();
        };
        \Swoole\Process::signal(SIGTERM, $shutdown);
        \Swoole\Process::signal(SIGINT, $shutdown);

        // 间隔（毫秒，秒级，可配置）
        $settleMs  = max(500, (int) \think\facade\Config::get('payment.settle_interval_ms', 1000));
        $channelMs = max(1000, (int) \think\facade\Config::get('payment.channel_interval_ms', 2000));
        $timeoutMs = max(1000, (int) \think\facade\Config::get('payment.timeout_interval_ms', 10000));
        $this->out($output, "[monitor] tick: settle={$settleMs}ms channel={$channelMs}ms timeout={$timeoutMs}ms");

        // 续锁 + 心跳：固定 5 秒（与锁 TTL=30s 匹配，独立于结算间隔）
        \Swoole\Timer::tick(5000, function () {
            $this->renewInstanceLock();
            $this->heartbeat();
        });
        // 结算（秒级）
        \Swoole\Timer::tick($settleMs, fn() => $this->safe('settle', $output, fn() => $this->doSettle()));
        // 通道到账 / 在线巡检（秒级：越小到账越快）
        \Swoole\Timer::tick($channelMs, fn() => $this->safe('channel', $output, fn() => $this->doChannel()));
        // 订单超时清理
        \Swoole\Timer::tick($timeoutMs, fn() => $this->safe('timeout', $output, fn() => $this->doTimeout()));
        // 云端授权心跳（内部自节流至 CloudAuth::REFRESH_INTERVAL）
        \Swoole\Timer::tick(120000, fn() => $this->safe('cloud', $output, fn() => $this->doCloud()));
        // 订单监控（每 30 秒汇总今日下单/成功/待支付/金额）
        \Swoole\Timer::tick(30000, fn() => $this->safe('order', $output, fn() => $this->doOrders()));

        // 启动即各跑一次
        $this->heartbeat();
        $this->safe('cloud', $output, fn() => $this->doCloud());
        $this->safe('order', $output, fn() => $this->doOrders());
        $this->safe('channel', $output, fn() => $this->doChannel());
        $this->safe('timeout', $output, fn() => $this->doTimeout());
        $this->safe('settle', $output, fn() => $this->doSettle());

        \Swoole\Event::wait();
        return 0;
    }

    protected function safe(string $tag, Output $output, callable $fn): void
    {
        try {
            $msg = $fn();
            if ($msg) {
                $this->out($output, '[' . date('H:i:s') . "][$tag] $msg");
            }
        } catch (\Throwable $e) {
            $this->out($output, '[' . date('H:i:s') . "][$tag][ERR] " . $e->getMessage());
        }
    }

    /** 输出到控制台(supervisor) + 站内 runtime/monitor.log（后台系统监控页可读） */
    protected function out(Output $output, string $line): void
    {
        $output->writeln($line);
        $this->fileLog($line);
    }

    /** 追加写入 runtime/monitor.log（容量超 2MB 保留后 1MB） */
    protected function fileLog(string $line): void
    {
        try {
            $file = root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'monitor.log';
            if (is_file($file) && filesize($file) > 2 * 1024 * 1024) {
                @file_put_contents($file, substr((string) @file_get_contents($file), -1024 * 1024));
            }
            @file_put_contents($file, $line . "\n", FILE_APPEND);
        } catch (\Throwable $e) {
        }
    }

    protected function doSettle(): string
    {
        $s = (new SettlementService())->run();
        return $s['paid'] > 0 ? "结算 matched={$s['matched']} paid={$s['paid']} notified={$s['notified']}" : '';
    }

    protected function doCloud(): string
    {
        $g = \app\core\CloudAuth::refresh();
        // 仅在未授权时记录，正常授权(ok)不刷屏
        return (isset($g['authorized']) && !$g['authorized']) ? '云端授权 DENIED（站点未授权/已到期）' : '';
    }

    protected function doChannel(): string
    {
        $s = (new ChannelMonitorService())->monitorChannels();
        return "通道巡检 checked={$s['checked']} online={$s['online']} offline={$s['offline']} bills={$s['bills']}";
    }

    protected function doTimeout(): string
    {
        $n = (new ChannelMonitorService())->timeoutOrders();
        return $n > 0 ? "订单超时 处理={$n}" : '';
    }

    /** 订单监控：今日下单/成功/待支付/成功金额 汇总 */
    protected function doOrders(): string
    {
        $todayStart = strtotime(date('Y-m-d'));
        $today   = PayOrder::where('create_time', '>=', $todayStart)->count();
        $paid    = PayOrder::where('create_time', '>=', $todayStart)->where('status', 1)->count();
        $pending = PayOrder::where('status', 0)->count();
        $amount  = (float) (PayOrder::where('create_time', '>=', $todayStart)->where('status', 1)->sum('money') ?: 0);
        return "订单监控 今日下单={$today} 成功={$paid} 待支付(全部)={$pending} 今日成功金额=" . number_format($amount, 2);
    }

    // ---- Redis 单实例锁 / 心跳（Redis 不可用则降级：不强制单实例，但不阻断运行） ----

    protected function getRedis()
    {
        try {
            return Cache::store('redis')->handler();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function genToken(): string
    {
        return gethostname() . '|' . getmypid() . '|' . bin2hex(random_bytes(4));
    }

    protected function acquireInstanceLock(): bool
    {
        $redis = $this->getRedis();
        if (!$redis) return true; // Redis 不可用，降级放行
        try {
            if ($redis->set($this->lockKey, $this->token, ['nx', 'ex' => $this->lockTtl])) {
                return true;
            }
            // 抢锁失败：若持有者是本机且进程已死（如上次被 SIGKILL / 崩溃），夺锁。
            // 避免 supervisor 硬杀后残留锁导致重启拉不起来(crash loop)。
            $holder = (string) $redis->get($this->lockKey);
            $parts  = explode('|', $holder);
            if (count($parts) >= 2 && $parts[0] === gethostname()) {
                $pid = (int) $parts[1];
                if ($pid > 0 && !posix_kill($pid, 0)) { // 进程不存在
                    $redis->set($this->lockKey, $this->token, ['ex' => $this->lockTtl]); // 覆盖夺锁
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    }

    protected function renewInstanceLock(): void
    {
        $redis = $this->getRedis();
        if (!$redis) return;
        try {
            // 仅当仍是本进程持有时续期（Lua 保证原子）
            $lua = "if redis.call('get',KEYS[1])==ARGV[1] then return redis.call('expire',KEYS[1],ARGV[2]) else return 0 end";
            $redis->eval($lua, [$this->lockKey, $this->token, $this->lockTtl], 1);
        } catch (\Throwable $e) {
        }
    }

    protected function releaseInstanceLock(): void
    {
        $redis = $this->getRedis();
        if (!$redis) return;
        try {
            $lua = "if redis.call('get',KEYS[1])==ARGV[1] then return redis.call('del',KEYS[1]) else return 0 end";
            $redis->eval($lua, [$this->lockKey, $this->token], 1);
        } catch (\Throwable $e) {
        }
    }

    protected function heartbeat(): void
    {
        $redis = $this->getRedis();
        if (!$redis) return;
        try {
            $redis->set($this->beatKey, (string) time(), ['ex' => 120]);
        } catch (\Throwable $e) {
        }
    }
}
