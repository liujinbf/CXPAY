<?php

declare(strict_types=1);

namespace support;

use Throwable;

/**
 * 结构化 JSON 统一日志工具类
 *
 * 特性：
 *   1. 自动提取当前请求的 request_id、客户端 IP、操作用户等上下文
 *   2. 以标准 JSON 结构化格式输出，无缝适配 ELK / Loki / Fluentd / CloudWatch
 *   3. 进程安全且不依赖容器完整初始化，适合全生命周期使用
 */
final class StructuredLog
{
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::write('DEBUG', $message, $context);
    }

    public static function write(string $level, string $message, array $context = []): void
    {
        try {
            $requestId = '';
            $ip        = '';
            $user      = '';

            try {
                if (function_exists('request')) {
                    $req = request();
                    if ($req) {
                        $requestId = (string)($req->context['request_id'] ?? $req->header('x-request-id') ?? '');
                        $ip        = (string)$req->getRemoteIp();
                        $session   = $req->session();
                        if ($session) {
                            $user = (string)($session->get('admin_info')['username'] ?? '');
                        }
                    }
                }
            } catch (Throwable) {
            }

            $logData = [
                'timestamp'  => date('Y-m-d H:i:s'),
                'level'      => strtoupper($level),
                'request_id' => $requestId,
                'client_ip'  => $ip,
                'operator'   => $user,
                'message'    => $message,
                'context'    => $context,
            ];

            $jsonString = json_encode($logData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // 写入日志
            $logDir = (function_exists('runtime_path') ? runtime_path() : dirname(__DIR__) . '/runtime') . '/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0777, true);
            }
            $logFile = $logDir . '/cxpay-' . date('Y-m-d') . '.log';
            @file_put_contents($logFile, $jsonString . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
        }
    }
}
