<?php

namespace app\common\service;

use think\facade\Db;
use think\facade\Config;
use app\common\model\PayChannel;
use app\common\model\PayOrder;
use app\common\model\PayCallbill;
use app\common\library\payment\PaymentManager;
use app\common\library\notify\NotifyService;

/**
 * 通道监控 / 订单超时 服务（供常驻 worker 调用）
 *
 * 对应旧 crontab 的 CheckCurlCallback(心跳/取单) + 订单超时处理：
 *   - server 通道：调 driver->monitor() 校验(Cookie等)+取增量到账→写账单，更新在线状态/配置
 *   - push   通道：check_time 超时未上报则置离线（心跳由端上 openapi 上报）
 *   - 订单超时：未支付订单超时置 status=2
 */
class ChannelMonitorService
{
    // push 通道心跳超时（秒）
    protected int $pushTimeout;
    // 订单未支付超时（秒）
    protected int $orderTimeout;

    public function __construct()
    {
        $this->pushTimeout  = (int) Config::get('payment.push_heartbeat_timeout', 120);
        $this->orderTimeout = (int) Config::get('payment.order_timeout', 600);
    }

    /**
     * 巡检所有通道：server 主动监控、push 超时下线
     * @return array{checked:int, online:int, offline:int, bills:int}
     */
    public function monitorChannels(): array
    {
        $stats = ['checked' => 0, 'online' => 0, 'offline' => 0, 'bills' => 0];
        $now   = time();

        $channels = PayChannel::select();
        foreach ($channels as $ch) {
            $cType = $ch->c_type;
            if (!PaymentManager::has($cType)) {
                continue;
            }
            $driver = PaymentManager::make($cType);
            $mode   = $driver->monitorMode();
            if ($mode === 'none') {
                continue;
            }
            $stats['checked']++;

            if ($mode === 'push') {
                // 端上心跳超时(含从未上报 check_time=0) → 离线
                $ct    = (int) $ch->check_time;
                $fresh = $ct > 0 && $ct >= $now - $this->pushTimeout;
                if ($ch->status == 1 && !$fresh) {
                    $update = ['status' => 0, 'endtime' => $now];
                    $this->applyOnlineTiming($ch, false, $now, $update);
                    PayChannel::where('id', $ch->id)->update($update);
                    $this->notifyOffline($ch);
                    $stats['offline']++;
                } elseif ($ch->status == 1) {
                    if ((int) $ch->online_time <= 0) {
                        PayChannel::where('id', $ch->id)->update(['online_time' => $now]);
                    }
                    $stats['online']++;
                } else {
                    $stats['offline']++;
                }
                continue;
            }

            // server 模式：主动监控（订单驱动——有待支付订单才高频轮询账单，否则低频心跳，避免限流）
            $hasPending = PayOrder::where(['channel_id' => $ch->id, 'status' => 0])->count() > 0;
            $cooldown   = $hasPending ? 1 : 30;
            if ($now - (int) $ch->check_time < $cooldown) {
                ($ch->status == 1) ? $stats['online']++ : $stats['offline']++;
                continue; // 冷却中，跳过本轮，减少账单接口调用
            }

            $config = PayChannel::decryptConfig($ch->config);
            try {
                $newConfig = $driver->monitor($ch->toArray(), $config);
            } catch (\Throwable $e) {
                continue;
            }

            $online = isset($newConfig['status']) ? (bool) $newConfig['status'] : ($ch->status == 1);

            // 写增量到账账单（按账号级去重：同 uid + config 视为同一笔到账，仅入库一次）
            // 修复：同账号配多通道时，一笔到账会产多条账单 → 后续订单误匹配残留账单。
            $bills = $newConfig['bill'] ?? false;
            if (is_array($bills)) {
                foreach ($bills as $bill) {
                    if (($bill['price'] ?? 0) <= 0) continue;
                    $bcfg = is_string($bill['config'] ?? false) ? $bill['config'] : '';
                    // 账号级去重（uid + config）：同账号多通道共用一个支付宝账户时不重复入库同一笔到账。
                    // config='' 的老通道（无流水号）仍按原逻辑入库（不去重，防漏单）。
                    if ($bcfg !== '' && PayCallbill::where(['uid' => $ch->uid, 'config' => $bcfg])->find()) {
                        continue; // 该到账已入库（同账号任一通道），去重
                    }
                    PayCallbill::create([
                        'uid'        => $ch->uid,
                        'channel_id' => $ch->id,
                        'price'      => $bill['price'],
                        'config'     => $bcfg,
                        'status'     => 0,
                    ]);
                    $stats['bills']++;
                }
            }
            unset($newConfig['bill']); // 不入库到 config

            // 回写通道状态 + 配置(含最新余额基线)
            $update = [
                'status'     => $online ? 1 : 0,
                'check_time' => $now,
                'config'     => PayChannel::encryptConfig($newConfig),
            ];
            if (!$online) {
                $update['endtime'] = $now;
            }
            $this->applyOnlineTiming($ch, $online, $now, $update);
            PayChannel::where('id', $ch->id)->update($update);
            if ($ch->status == 1 && !$online) {
                $this->notifyOffline($ch);
            }
            $online ? $stats['online']++ : $stats['offline']++;
        }

        return $stats;
    }

    /**
     * 通道掉线通知该通道所属商户（best-effort，默认开）
     */
    protected function notifyOffline($ch): void
    {
        $user = Db::name('user')->where('id', (int) $ch->uid)->find();
        if (!$user) return;
        NotifyService::send('channel_offline', $user, [
            '[channel]' => NotifyService::channelLabel((string) $ch->type, (string) $ch->c_type, (string) $ch->notes),
            '[notes]'   => (string) $ch->c_type,
        ]);
    }

    /**
     * 维护在线时长字段：上线记 online_time，下线累加 online_total（秒）。
     * @param object $ch      当前通道(含旧 status/online_time/online_total)
     * @param bool   $nowOnline 本轮判定是否在线
     * @param int    $now
     * @param array  $update   会写回的更新数组(引用)
     */
    protected function applyOnlineTiming($ch, bool $nowOnline, int $now, array &$update): void
    {
        $onlineTime  = (int) $ch->online_time;
        $onlineTotal = (int) $ch->online_total;
        if ($nowOnline) {
            if ((int) $ch->status === 0) {
                // 从离线切到在线：重新开始计时
                $update['online_time'] = $now;
            } elseif ($onlineTime <= 0) {
                // 首次上线
                $update['online_time'] = $now;
            }
        } else {
            if ((int) $ch->status === 1 && $onlineTime > 0) {
                $update['online_total'] = $onlineTotal + max(0, $now - $onlineTime);
            }
            $update['online_time'] = 0;
        }
    }

    /**
     * 未支付订单超时处理 → status=2
     *   - 新单：按订单 expire_time（= 下单时商户 pay_outtime）关单，与收银台倒计时一致
     *   - 历史单(expire_time=0)：回退全局 order_timeout
     * @return int 处理数量
     */
    public function timeoutOrders(): int
    {
        $now = time();
        // 按每单到期时间关单
        $count = PayOrder::where('status', 0)
            ->where('expire_time', '>', 0)
            ->where('expire_time', '<', $now)
            ->update(['status' => 2]);
        // 兼容无 expire_time 的历史单
        $count += PayOrder::where('status', 0)
            ->where('expire_time', 0)
            ->where('create_time', '<', $now - $this->orderTimeout)
            ->update(['status' => 2]);
        return (int) $count;
    }
}
