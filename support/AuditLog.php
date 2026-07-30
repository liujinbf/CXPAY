<?php

declare(strict_types=1);

namespace support;

use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/**
 * 管理员操作审计日志工具类
 *
 * 将敏感操作（补单、关单、修改商户费率、修改通道配置等）写入 cx_audit_log 表，
 * 提供事后审计能力。写入失败仅记录 error_log，不影响主流程。
 */
class AuditLog
{
    /**
     * 记录管理员操作
     *
     * @param string $operator  操作人（管理员账号名）
     * @param string $action    操作类型（如 force_pay、close_order、update_merchant 等）
     * @param array  $context   操作上下文（trade_no、merchant_id 等相关数据）
     * @param string $result    操作结果（success | fail）
     * @param string $ip        操作来源 IP
     */
    public static function record(
        string $operator,
        string $action,
        array  $context = [],
        string $result  = 'success',
        string $ip      = ''
    ): void {
        try {
            DB::table('cx_audit_log')->insert([
                'operator'   => mb_substr($operator, 0, 64),
                'action'     => mb_substr($action, 0, 64),
                'context'    => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'result'     => $result === 'success' ? 'success' : 'fail',
                'ip'         => mb_substr($ip, 0, 64),
                'created_at' => time(),
            ]);
        } catch (Throwable $e) {
            // 审计写入失败不能中断主流程，仅记录错误
            error_log('[AuditLog] 写入失败 action=' . $action . ' error=' . $e->getMessage());
        }
    }

    /**
     * 获取当前管理员账号（从 Session 中读取，无 Session 则返回 unknown）
     */
    public static function currentOperator(): string
    {
        try {
            $session = request()?->session();
            return $session ? (string)($session->get('admin_info')['username'] ?? 'unknown') : 'system';
        } catch (Throwable) {
            return 'system';
        }
    }

    /**
     * 获取当前请求来源 IP
     */
    public static function currentIp(): string
    {
        try {
            return (string)(request()?->getRemoteIp() ?? '');
        } catch (Throwable) {
            return '';
        }
    }
}
