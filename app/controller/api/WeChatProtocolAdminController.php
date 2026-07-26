<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\WeChatProtocolCloudService;
use support\Response;
use Throwable;

/**
 * 微信小账本/收款单扫码授权免挂 API 控制器
 */
class WeChatProtocolAdminController
{
    protected WeChatProtocolCloudService $protocolService;

    public function __construct()
    {
        $this->protocolService = new WeChatProtocolCloudService();
    }

    /**
     * 发起扫码登录会话获取授权二维码 /api/wxprotocol/login_qr
     */
    public function getLoginQr(): Response
    {
        $session = $this->protocolService->createQrSession();
        return json($session);
    }

    /**
     * 轮询扫码授权状态 /api/wxprotocol/poll_qr
     */
    public function pollQr(object $request): Response
    {
        $sessionId = $request->get('session_id') ?? $request->post('session_id') ?? '';
        $res = $this->protocolService->pollQrSession($sessionId);
        return json($res);
    }
}
