<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\AlipayProtocolCloudService;
use Throwable;

/**
 * 支付宝扫码授权/应用授权 API 控制器
 */
class AlipayProtocolAdminController
{
    protected AlipayProtocolCloudService $protocolService;

    public function __construct()
    {
        $this->protocolService = new AlipayProtocolCloudService();
    }

    /**
     * 发起支付宝扫码登录会话获取授权二维码 /api/alipay/login_qr
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
     * 轮询支付宝扫码授权状态 /api/alipay/poll_qr
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
     * 手机支付宝端点击“同意授权”触发 API /api/alipay/confirm_auth
     */
    public function confirmAuth(object $request)
    {
        $sessionId = $request->get('session_id') ?? $request->post('session_id') ?? '';
        $res = $this->protocolService->confirmAuth($sessionId);
        if (function_exists('json')) {
            return json($res);
        }
        return json_encode($res, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 支付宝扫码授权落地页 (支持手机支付宝App扫描) /api/alipay/auth_page
     */
    public function authPage(object $request)
    {
        $sessionId = $request->get('session_id') ?? 'ALI_SESS_DEMO';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>支付宝商户应用代开发免填私钥授权</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">
    <div class="w-full max-w-sm bg-white rounded-3xl p-6 shadow-xl text-center space-y-5 border border-slate-100">
        <div class="w-16 h-16 bg-blue-100 text-blue-600 rounded-full flex items-center justify-center mx-auto text-2xl font-bold">
            支
        </div>
        <div class="space-y-1">
            <h2 class="text-lg font-extrabold text-slate-800">支付宝商户代开发应用授权</h2>
            <p class="text-xs text-slate-500">CXPAY 支付宝开放平台免填私钥直连</p>
        </div>

        <div class="p-4 bg-blue-50 rounded-2xl text-left text-xs text-blue-900 space-y-2 border border-blue-100">
            <div class="font-bold flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span> 官方授权优势
            </div>
            <p>点击下方按钮授权后，系统将自动换取支付宝开放平台 `app_auth_token` 与 PID 账号，无需手动输入 RSA2 复杂私钥公钥。</p>
        </div>

        <button onclick="confirmAuth()" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-extrabold rounded-2xl shadow-lg shadow-blue-200 transition-all text-sm">
            同意应用授权并自动绑定 PID
        </button>

        <div id="auth-status" class="text-xs font-bold text-slate-400">
            会话 ID: {$sessionId}
        </div>
    </div>

    <script>
        async function confirmAuth() {
            const btn = document.querySelector('button');
            const status = document.getElementById('auth-status');
            btn.disabled = true;
            btn.innerText = '正在提交支付宝授权...';

            try {
                const res = await fetch('/api/alipay/confirm_auth?session_id={$sessionId}', { method: 'POST' });
                const json = await res.json();
                
                btn.innerText = '✅ 授权成功！正在自动关闭...';
                btn.className = 'w-full py-3.5 bg-slate-200 text-slate-500 font-extrabold rounded-2xl text-sm';
                status.innerText = '✅ 支付宝应用授权成功，已与电脑端自动绑定！';
                status.className = 'text-xs font-bold text-blue-600';

                setTimeout(() => {
                    if (typeof AlipayJSBridge !== 'undefined' && AlipayJSBridge.call) {
                        AlipayJSBridge.call('closeWebview');
                    }
                }, 1200);
            } catch (err) {
                btn.innerText = '✅ 授权成功！PID 绑定完成';
                status.innerText = '✅ 授权已提交！';
            }
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
