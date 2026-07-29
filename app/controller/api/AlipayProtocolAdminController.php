<?php

declare(strict_types=1);

namespace app\controller\api;

use support\Request;
use support\Response;

/**
 * 支付宝协议授权占位接口。
 * 未接入支付宝 ISV OAuth 前必须明确不可用，禁止伪造授权结果。
 */
class AlipayProtocolAdminController
{
    public function getLoginQr(): Response { return $this->unavailable(); }

    public function pollQr(Request $request): Response { return $this->unavailable(); }

    public function confirmAuth(Request $request): Response { return $this->unavailable(); }

    public function authPage(Request $request): Response { return $this->unavailable(); }

    private function unavailable(): Response
    {
        return json(['code' => -1, 'msg' => '尚未配置支付宝 ISV OAuth，扫码授权暂不可用'])->withStatus(501);
    }
}
