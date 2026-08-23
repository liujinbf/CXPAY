<?php

declare(strict_types=1);

namespace app\controller\admin;

use Illuminate\Database\Capsule\Manager as DB;
use support\Request;

/**
 * 管理员操作审计日志查询控制器（P1）
 *
 * 路由（均受 AdminAuthMiddleware 保护）：
 *   GET /api/admin/audit/list       — 分页查询，支持操作人/动作/结果/时间筛选
 *   GET /api/admin/audit/export_csv — CSV 导出（最近 7 天，最多 5000 条）
 */
final class AuditLogController
{
    public function list(Request $request): string
    {
        $page      = max(1, (int)$request->get('page', 1));
        $pageSize  = min(100, max(10, (int)$request->get('page_size', 20)));
        $operator  = trim((string)$request->get('operator', ''));
        $action    = trim((string)$request->get('action', ''));
        $result    = trim((string)$request->get('result', ''));
        $startTime = (int)$request->get('start_time', 0);
        $endTime   = (int)$request->get('end_time', 0);

        $query = DB::table('cx_audit_log')->orderByDesc('id');

        if ($operator !== '') {
            $query->where('operator', 'like', '%' . $operator . '%');
        }
        if ($action !== '') {
            $query->where('action', 'like', '%' . $action . '%');
        }
        if (in_array($result, ['success', 'fail'], true)) {
            $query->where('result', $result);
        }
        if ($startTime > 0) {
            $query->where('created_at', '>=', $startTime);
        }
        if ($endTime > 0) {
            $query->where('created_at', '<=', $endTime);
        }

        $total = $query->count();
        $rows  = $query->forPage($page, $pageSize)->get();

        return json_encode([
            'code' => 1,
            'data' => [
                'list'      => $rows->map(fn($r) => [
                    'id'         => $r->id,
                    'operator'   => $r->operator,
                    'action'     => $r->action,
                    'context'    => json_decode((string)$r->context, true) ?? [],
                    'result'     => $r->result,
                    'ip'         => $r->ip,
                    'created_at' => $r->created_at,
                    'created_at_str' => date('Y-m-d H:i:s', (int)$r->created_at),
                ])->values(),
                'total'     => $total,
                'page'      => $page,
                'page_size' => $pageSize,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function exportCsv(Request $request): \Webman\Http\Response
    {
        // 最多导出最近 7 天 5000 条
        $since = time() - 7 * 86400;
        $rows  = DB::table('cx_audit_log')
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(5000)
            ->get();

        $csv  = "\xEF\xBB\xBF"; // UTF-8 BOM
        $csv .= implode(',', ['ID', '操作人', '操作类型', '上下文', '结果', 'IP', '时间']) . "\n";
        foreach ($rows as $r) {
            $csv .= implode(',', [
                $r->id,
                '"' . str_replace('"', '""', (string)$r->operator) . '"',
                '"' . str_replace('"', '""', (string)$r->action) . '"',
                '"' . str_replace('"', '""', (string)$r->context) . '"',
                $r->result,
                $r->ip,
                date('Y-m-d H:i:s', (int)$r->created_at),
            ]) . "\n";
        }

        $filename = 'audit_log_' . date('Ymd_His') . '.csv';
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
