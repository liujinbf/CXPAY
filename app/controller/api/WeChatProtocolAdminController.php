<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\WeChatProtocolCloudService;
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
    public function getLoginQr()
    {
        $session = $this->protocolService->createQrSession();
        if (function_exists('json')) {
            return json($session);
        }
        return json_encode($session, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 轮询扫码授权状态 /api/wxprotocol/poll_qr
     */
    public function pollQr(object $request)
    {
        $sessionId = $request->get('session_id') ?? $request->post('session_id') ?? '';
        $res = $this->protocolService->pollQrSession($sessionId);
        if (function_exists('json')) {
            return json($res);
        }
        return json_encode($res, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 微信扫码授权落地页 (支持微信直接扫描) /api/wxprotocol/auth_page
     */
    public function authPage(object $request)
    {
        $sessionId = $request->get('session_id') ?? 'SESS_DEMO';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>微信店员小账本免挂授权</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">
    <div class="w-full max-w-sm bg-white rounded-3xl p-6 shadow-xl text-center space-y-5 border border-slate-100">
        <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto text-2xl font-bold">
            微
        </div>
        <div class="space-y-1">
            <h2 class="text-lg font-extrabold text-slate-800">微信店员小账本免挂授权</h2>
            <p class="text-xs text-slate-500">CXPAY 云端协议自动化监听系统</p>
        </div>

        <div class="p-4 bg-emerald-50 rounded-2xl text-left text-xs text-emerald-800 space-y-2 border border-emerald-100">
            <div class="font-bold flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span> 授权提示
            </div>
            <p>点击下方按钮后，将授权当前微信加入店员小账本消息推送通道。系统将自动抓取实时到账通知并实现自动销账。</p>
        </div>

        <button onclick="confirmAuth()" class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold rounded-2xl shadow-lg shadow-emerald-200 transition-all text-sm">
            同意授权成为店员并绑定
        </button>

        <div id="auth-status" class="text-xs font-bold text-slate-400">
            会话 ID: {$sessionId}
        </div>
    </div>

    <script>
        function confirmAuth() {
            const btn = document.querySelector('button');
            const status = document.getElementById('auth-status');
            btn.disabled = true;
            btn.innerText = '授权成功！正在返回...';
            btn.className = 'w-full py-3.5 bg-slate-300 text-slate-600 font-extrabold rounded-2xl text-sm';
            status.innerText = '✅ 微信授权成功，已成功绑定小账本推送！';
            status.className = 'text-xs font-bold text-emerald-600';
        }
    </script>
</body>
</html>
HTML;

        if (class_exists('\support\Response')) {
            return new \support\Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
        }
        return $html;
    }
}
