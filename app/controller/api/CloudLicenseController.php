<?php

declare(strict_types=1);

namespace app\controller\api;

use support\Request;
use support\Response;

/**
 * 旧云端接口兼容墓碑。
 *
 * 云端授权、账号、源码包和插件资产已经迁移到独立控制面。保留这些方法仅用于
 * 阻止历史调用静默落入默认路由，不得在支付节点中恢复云端业务实现。
 */
final class CloudLicenseController
{
    public function getWxLoginQr(): Response
    {
        return $this->retired();
    }

    public function pollWxLogin(Request $request): Response
    {
        return $this->retired();
    }

    public function getQqLoginQr(): Response
    {
        return $this->retired();
    }

    public function pollQqLogin(Request $request): Response
    {
        return $this->retired();
    }

    public function sendEmailCode(Request $request): Response
    {
        return $this->retired();
    }

    public function bindQq(Request $request): Response
    {
        return $this->retired();
    }

    public function downloadPackage(Request $request): Response
    {
        return $this->retired();
    }

    public function traceLeaked(Request $request): Response
    {
        return $this->retired();
    }

    public function getSiteInfo(Request $request): Response
    {
        return $this->retired();
    }

    public function renewModule(Request $request): Response
    {
        return $this->retired();
    }

    public function resetKey(Request $request): Response
    {
        return $this->retired();
    }

    public function changeDomain(Request $request): Response
    {
        return $this->retired();
    }

    public function pluginMarketList(Request $request): Response
    {
        return $this->retired();
    }

    public function pluginBuy(Request $request): Response
    {
        return $this->retired();
    }

    public function pluginDownload(Request $request): Response
    {
        return $this->retired();
    }

    private function retired(): Response
    {
        return json([
            'code' => -1,
            'error_code' => 'CLOUD_CONTROL_PLANE_REQUIRED',
            'msg' => '云端授权服务已从 CXPAY 支付节点迁移，请前往独立云端工作台操作',
            'data' => [
                'action' => 'OPEN_PORTAL',
                'portal_url' => (string)config('cloud.portal_url', 'https://cloud.cxpay.com'),
            ],
        ])->withStatus(410);
    }
}
