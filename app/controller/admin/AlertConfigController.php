<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\service\AlertNotificationService;
use support\Request;

/**
 * 管理员通知配置 API
 */
class AlertConfigController
{
    private AlertNotificationService $alertService;

    public function __construct()
    {
        $this->alertService = new AlertNotificationService();
    }

    /** GET /api/admin/alert/config — 读取管理员通知配置 */
    public function getConfig(Request $request): string
    {
        $config = $this->alertService->getAdminConfig();
        return json_encode(['code' => 1, 'data' => $config], JSON_UNESCAPED_UNICODE);
    }

    /** POST /api/admin/alert/config — 保存管理员通知配置 */
    public function saveConfig(Request $request): string
    {
        $data   = $request->post();
        $result = $this->alertService->saveAdminConfig($data);
        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /** POST /api/admin/alert/test — 发送测试通知 */
    public function sendTest(Request $request): string
    {
        $channel = trim((string)($request->post('channel') ?? ''));
        if (!in_array($channel, ['email', 'wxwork', 'webhook'], true)) {
            return json_encode(['code' => -1, 'msg' => '无效的通知渠道'], JSON_UNESCAPED_UNICODE);
        }
        $ok = $this->alertService->sendTest('admin', $channel);
        return json_encode([
            'code' => $ok ? 1 : -1,
            'msg'  => $ok ? '测试通知已发送，请检查接收端' : '发送失败，请检查配置是否正确',
        ], JSON_UNESCAPED_UNICODE);
    }
}
