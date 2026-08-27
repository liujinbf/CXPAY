<?php

declare(strict_types=1);

namespace app\controller\admin;

use support\Request;
use support\Response;

/**
 * 代理商功能迁移墓碑控制器 (Agent Hub Retired Controller)
 *
 * 架构说明 (Codex Architecture):
 *   根据 CXPAY 独立云端控制面与多租户设计规范，代理商体系、全网站点授权下发、
 *   插件代开与双分录财务账本已完全独立并迁移至官方独立云端工作台（https://cloud.fcwan.cn）。
 *   主站（CXPAY Runtime）作为纯净聚合支付网关，不承载任何代理商业务逻辑。
 */
final class AgentHubController
{
    public function profile(Request $request): Response
    {
        return $this->retired();
    }

    public function issueLicense(Request $request): Response
    {
        return $this->retired();
    }

    public function listSubInstances(Request $request): Response
    {
        return $this->retired();
    }

    public function revokeLicense(Request $request): Response
    {
        return $this->retired();
    }

    public function restoreLicense(Request $request): Response
    {
        return $this->retired();
    }

    public function deleteLicense(Request $request): Response
    {
        return $this->retired();
    }

    public function rebindLicense(Request $request): Response
    {
        return $this->retired();
    }

    public function buyQuota(Request $request): Response
    {
        return $this->retired();
    }

    public function pluginCatalog(Request $request): Response
    {
        return $this->retired();
    }

    public function instanceGrants(Request $request): Response
    {
        return $this->retired();
    }

    public function grantPlugin(Request $request): Response
    {
        return $this->retired();
    }

    private function retired(): Response
    {
        return json([
            'code' => -1,
            'error_code' => 'AGENT_HUB_MOVED_TO_CLOUD_PORTAL',
            'msg' => '代理商控制台已完全迁移至官方独立云端工作台，请登录云端控制面操作',
            'data' => [
                'action' => 'OPEN_PORTAL',
                'portal_url' => rtrim((string)config('cloud.portal_url', 'https://cloud.fcwan.cn'), '/'),
            ],
        ])->withStatus(410);
    }
}
