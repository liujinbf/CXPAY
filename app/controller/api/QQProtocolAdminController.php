<?php

declare(strict_types=1);

namespace app\controller\api;

use support\Request;
use support\Response;

/**
 * QQ 钱包协议授权占位接口。
 * 未接入真实 ptlogin 服务前必须明确不可用，禁止伪造授权结果。
 */
class QQProtocolAdminController
{
    public function getLoginQr(): Response { return $this->unavailable(); }

    public function pollQr(Request $request): Response { return $this->unavailable(); }

    public function confirmAuth(Request $request): Response { return $this->unavailable(); }

    public function authPage(Request $request): Response { return $this->unavailable(); }

    private function unavailable(): Response
    {
        return json(['code' => -1, 'msg' => '尚未配置 QQ ptlogin 服务，扫码授权暂不可用'])->withStatus(501);
    }
}
