<?php

declare(strict_types=1);

namespace app\controller\api;

use support\Request;
use support\Response;

/**
 * 微信协议授权占位接口。
 * 未接入真实微信授权服务前必须明确不可用，禁止伪造授权结果。
 */
class WeChatProtocolAdminController
{
    public function getLoginQr(): Response { return $this->unavailable(); }

    public function pollQr(Request $request): Response { return $this->unavailable(); }

    public function confirmAuth(Request $request): Response { return $this->unavailable(); }

    public function authPage(Request $request): Response { return $this->unavailable(); }

    private function unavailable(): Response
    {
        return json([
            'code' => -1,
            'error_code' => 'PROTOCOL_SERVICE_NOT_CONFIGURED',
            'msg' => '尚未配置微信授权服务，协议扫码功能暂不可用',
            'data' => [
                'channel' => 'wechat',
                'status'  => 'UNAVAILABLE',
            ]
        ])->withStatus(501);
    }
}
