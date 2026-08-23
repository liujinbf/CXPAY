<?php

declare(strict_types=1);

namespace app\controller;

use Illuminate\Database\Capsule\Manager as DB;
use support\Request;
use Webman\Http\Response;

/**
 * 生产健康探针控制器
 *
 * GET /health        — liveness + readiness 综合探针
 * GET /health/live   — 仅 liveness（进程存活，不检查依赖）
 * GET /health/ready  — readiness（依赖全部就绪才返回 200）
 *
 * 返回格式：
 * {
 *   "status": "ok" | "degraded",
 *   "checks": {
 *     "db":    {"status":"ok"|"fail", "latency_ms": 2},
 *     "redis": {"status":"ok"|"fail", "latency_ms": 1}
 *   },
 *   "uptime_s": 12345
 * }
 */
final class HealthController
{
    /** 进程启动时间（常驻内存，跨请求共享） */
    private static int $bootTime = 0;

    public static function init(): void
    {
        if (self::$bootTime === 0) {
            self::$bootTime = time();
        }
    }

    public static function getBootTime(): int
    {
        return self::$bootTime;
    }

    /**
     * GET /health — 综合探针（liveness + readiness）
     * HTTP 200：全部健康 | HTTP 503：任一依赖异常
     */
    public function index(Request $request): Response
    {
        $db    = $this->checkDb();
        $redis = $this->checkRedis();

        $allOk  = $db['status'] === 'ok' && $redis['status'] === 'ok';
        $status = $allOk ? 'ok' : 'degraded';

        $body = json_encode([
            'status'   => $status,
            'checks'   => ['db' => $db, 'redis' => $redis],
            'uptime_s' => time() - (self::$bootTime ?: time()),
            'ts'       => time(),
        ], JSON_UNESCAPED_UNICODE);

        return response($body, $allOk ? 200 : 503, [
            'Content-Type'  => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * GET /health/live — 仅 liveness（进程存活即可，不检查外部依赖）
     * 始终返回 200，避免 K8s 因外部依赖故障重启仍健康的进程。
     */
    public function live(Request $request): Response
    {
        return response(
            json_encode(['status' => 'ok', 'ts' => time()]),
            200,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    /**
     * GET /health/ready — readiness 探针（依赖全部就绪才接收流量）
     */
    public function ready(Request $request): Response
    {
        $db    = $this->checkDb();
        $redis = $this->checkRedis();
        $allOk = $db['status'] === 'ok' && $redis['status'] === 'ok';

        return response(
            json_encode([
                'status' => $allOk ? 'ok' : 'degraded',
                'checks' => ['db' => $db, 'redis' => $redis],
            ], JSON_UNESCAPED_UNICODE),
            $allOk ? 200 : 503,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    // --- 内部探测方法 ---------------------------------------------------------

    private function checkDb(): array
    {
        $start = hrtime(true);
        try {
            DB::connection()->getPdo()->query('SELECT 1');
            $ms = (int)round((hrtime(true) - $start) / 1_000_000);
            return ['status' => 'ok', 'latency_ms' => $ms];
        } catch (\Throwable $e) {
            $ms = (int)round((hrtime(true) - $start) / 1_000_000);
            return ['status' => 'fail', 'latency_ms' => $ms, 'error' => $e->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        $start = hrtime(true);
        try {
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

            $ms = (int)round((hrtime(true) - $start) / 1_000_000);
            $ok = ($pong === true || $pong === 1 || str_contains((string)$pong, 'PONG') || (string)$pong === '+PONG');
            return ['status' => $ok ? 'ok' : 'fail', 'latency_ms' => $ms];
        } catch (\Throwable $e) {
            $ms = (int)round((hrtime(true) - $start) / 1_000_000);
            return ['status' => 'fail', 'latency_ms' => $ms, 'error' => $e->getMessage()];
        }
    }

}
