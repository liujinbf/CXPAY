<?php

declare(strict_types=1);

namespace app\controller\admin;

use support\Request;
use support\Response;

/**
 * CXPAY 本地插件商城与独立云端之间的临时边界。
 *
 * M0 阶段不允许回退到域名 + 授权 Key 协议；实例激活协议接入后由这里承接目录和下载。
 */
final class CloudPluginMarketController
{
    public function getCloudMarket(): Response
    {
        return $this->response(
            503,
            'CLOUD_INSTANCE_ACTIVATION_REQUIRED',
            '请先完成 CXPAY 实例激活，再从独立云端获取插件目录',
            'ACTIVATE_INSTANCE'
        );
    }

    public function buyFromCloud(Request $request): Response
    {
        return $this->response(
            409,
            'CLOUD_PURCHASE_MOVED_TO_PORTAL',
            '插件购买和续费已迁移至独立云端工作台',
            'OPEN_PORTAL'
        );
    }

    public function downloadFromCloud(Request $request): Response
    {
        return $this->response(
            503,
            'CLOUD_INSTANCE_ACTIVATION_REQUIRED',
            '旧域名授权 Key 下载协议已停用，请先激活当前 CXPAY 实例',
            'ACTIVATE_INSTANCE'
        );
    }

    private function response(int $status, string $errorCode, string $message, string $action): Response
    {
        return json([
            'code' => -1,
            'error_code' => $errorCode,
            'msg' => $message,
            'data' => [
                'action' => $action,
                'portal_url' => rtrim(
                    (string)config('cloud.portal_url', 'https://cloud.cxpay.com'),
                    '/'
                ) . '/plugins',
            ],
        ])->withStatus($status);
    }
}
