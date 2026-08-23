<?php

declare(strict_types=1);

namespace app\controller;

use Illuminate\Database\Capsule\Manager as DB;
use support\Request;
use Webman\Http\Response;

/**
 * Prometheus / OpenMetrics 指标抓取端点（P2）
 *
 * GET /metrics
 *
 * 安全策略（双重保护）：
 *   1. 仅允许来自 127.0.0.1 / ::1 的请求直接访问（Prometheus 与应用同节点部署）
 *   2. 若请求来自外部 IP，必须携带 Authorization: Bearer <METRICS_SCRAPE_TOKEN>
 *      （在 config/metrics.php 或环境变量 METRICS_SCRAPE_TOKEN 中配置）
 *
 * 输出格式：OpenMetrics / Prometheus text format（Content-Type: text/plain）
 *
 * 可接入 Grafana Dashboard：导入 ID 11835（PHP FPM / PHP App 通用模板）或自定义。
 */
final class MetricsController
{
    public function scrape(Request $request): Response
    {
        // ── 访问控制 ────────────────────────────────────────────────────────────
        $ip = $request->getRemoteIp();
        $isLocalhost = in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);

        if (!$isLocalhost) {
            $scrapeToken = (string)getenv('METRICS_SCRAPE_TOKEN');
            if ($scrapeToken === '') {
                // 未配置 scrape token 时，外部访问一律拒绝
                return response('Forbidden', 403, ['Content-Type' => 'text/plain']);
            }
            $authHeader = trim(str_ireplace('Bearer ', '', (string)($request->header('authorization') ?? '')));
            if (!hash_equals($scrapeToken, $authHeader)) {
                return response('Unauthorized', 401, ['Content-Type' => 'text/plain']);
            }
        }

        $lines   = [];
        $now     = time() * 1000; // Prometheus 时间戳（毫秒）
        $prefix  = 'cxpay';

        // ── 1. PHP 运行时指标 ───────────────────────────────────────────────────
        $memBytes = memory_get_usage(true);
        $memPeak  = memory_get_peak_usage(true);
        $lines[] = "# HELP {$prefix}_memory_bytes PHP 进程当前内存占用（字节）";
        $lines[] = "# TYPE {$prefix}_memory_bytes gauge";
        $lines[] = "{$prefix}_memory_bytes {$memBytes}";

        $lines[] = "# HELP {$prefix}_memory_peak_bytes PHP 进程峰值内存占用（字节）";
        $lines[] = "# TYPE {$prefix}_memory_peak_bytes gauge";
        $lines[] = "{$prefix}_memory_peak_bytes {$memPeak}";

        // ── 2. 数据库连接池状态 ─────────────────────────────────────────────────
        $dbOk      = 0;
        $dbLatency = 0.0;
        try {
            $t0 = hrtime(true);
            DB::connection()->getPdo()->query('SELECT 1');
            $dbLatency = round((hrtime(true) - $t0) / 1e6, 3);
            $dbOk      = 1;
        } catch (\Throwable) {}

        $lines[] = "# HELP {$prefix}_db_up 数据库连接是否正常（1=正常，0=异常）";
        $lines[] = "# TYPE {$prefix}_db_up gauge";
        $lines[] = "{$prefix}_db_up {$dbOk}";

        $lines[] = "# HELP {$prefix}_db_query_latency_ms 数据库 SELECT 1 延迟（毫秒）";
        $lines[] = "# TYPE {$prefix}_db_query_latency_ms gauge";
        $lines[] = "{$prefix}_db_query_latency_ms {$dbLatency}";

        // ── 3. Redis 连接状态 ───────────────────────────────────────────────────
        $redisOk      = 0;
        $redisLatency = 0.0;
        try {
            $t0   = hrtime(true);
            $pong = null;
            if (class_exists(\Webman\Redis\Client::class)) {
                $pong = \Webman\Redis\Client::connection()->ping();
            } elseif (class_exists(\Illuminate\Support\Facades\Redis::class)) {
                try {
                    $pong = \Illuminate\Support\Facades\Redis::ping();
                } catch (\Throwable) {}
            }
            if ($pong === null && extension_loaded('redis')) {
                $redis = new \Redis();
                $host  = (string)(config('redis.default.host') ?? '127.0.0.1');
                $port  = (int)(config('redis.default.port') ?? 6379);
                $pwd   = config('redis.default.password') ?: null;
                if (@$redis->connect($host, $port, 1.0)) {
                    if ($pwd) {
                        @$redis->auth((string)$pwd);
                    }
                    $pong = $redis->ping();
                    $redis->close();
                }
            }
            $redisLatency = round((hrtime(true) - $t0) / 1e6, 3);
            $redisOk = ($pong === true || $pong === 1 || str_contains((string)$pong, 'PONG') || (string)$pong === '+PONG') ? 1 : 0;
        } catch (\Throwable) {}


        $lines[] = "# HELP {$prefix}_redis_up Redis 连接是否正常（1=正常，0=异常）";
        $lines[] = "# TYPE {$prefix}_redis_up gauge";
        $lines[] = "{$prefix}_redis_up {$redisOk}";

        $lines[] = "# HELP {$prefix}_redis_ping_latency_ms Redis PING 延迟（毫秒）";
        $lines[] = "# TYPE {$prefix}_redis_ping_latency_ms gauge";
        $lines[] = "{$prefix}_redis_ping_latency_ms {$redisLatency}";

        // ── 4. 业务指标（近 1 小时订单数、成功率）─────────────────────────────
        try {
            $since = time() - 3600;
            $total = (int)DB::table('cx_order')->where('create_time', '>=', $since)->count();
            $paid  = (int)DB::table('cx_order')->where('create_time', '>=', $since)->where('status', 1)->count();

            $lines[] = "# HELP {$prefix}_orders_total_1h 过去 1 小时订单总数";
            $lines[] = "# TYPE {$prefix}_orders_total_1h gauge";
            $lines[] = "{$prefix}_orders_total_1h {$total}";

            $lines[] = "# HELP {$prefix}_orders_paid_1h 过去 1 小时已付款订单数";
            $lines[] = "# TYPE {$prefix}_orders_paid_1h gauge";
            $lines[] = "{$prefix}_orders_paid_1h {$paid}";

            $successRate = $total > 0 ? round($paid / $total, 4) : 0.0;
            $lines[] = "# HELP {$prefix}_order_success_rate_1h 过去 1 小时订单成功率（0~1）";
            $lines[] = "# TYPE {$prefix}_order_success_rate_1h gauge";
            $lines[] = "{$prefix}_order_success_rate_1h {$successRate}";
        } catch (\Throwable) {}

        // ── 5. 在线商户数 ───────────────────────────────────────────────────────
        try {
            $merchantCount = (int)DB::table('cx_merchant')->where('status', 1)->count();
            $lines[] = "# HELP {$prefix}_merchants_active 当前启用的商户数量";
            $lines[] = "# TYPE {$prefix}_merchants_active gauge";
            $lines[] = "{$prefix}_merchants_active {$merchantCount}";
        } catch (\Throwable) {}

        // ── 6. 在线通道数 ───────────────────────────────────────────────────────
        try {
            $channelCount = (int)DB::table('cx_pay_channel')->where('status', 1)->where('online_status', 1)->count();
            $lines[] = "# HELP {$prefix}_channels_online 当前在线通道数量";
            $lines[] = "# TYPE {$prefix}_channels_online gauge";
            $lines[] = "{$prefix}_channels_online {$channelCount}";
        } catch (\Throwable) {}

        // ── 7. 进程运行时长 ─────────────────────────────────────────────────────
        if (\app\controller\HealthController::getBootTime() > 0) {
            $uptime = time() - \app\controller\HealthController::getBootTime();
            $lines[] = "# HELP {$prefix}_process_uptime_seconds 进程持续运行秒数";
            $lines[] = "# TYPE {$prefix}_process_uptime_seconds counter";
            $lines[] = "{$prefix}_process_uptime_seconds {$uptime}";
        }

        $body = implode("\n", $lines) . "\n";

        return response($body, 200, [
            'Content-Type'  => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
