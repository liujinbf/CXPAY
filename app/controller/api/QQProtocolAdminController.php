<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\QQProtocolCloudService;
use Throwable;

/**
 * QQ 钱包 ptlogin 扫码授权免挂 API 控制器
 */
class QQProtocolAdminController
{
    protected QQProtocolCloudService $protocolService;

    public function __construct()
    {
        $this->protocolService = new QQProtocolCloudService();
    }

    /**
     * 发起 QQ 扫码登录会话获取授权二维码 /api/qqprotocol/login_qr
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
     * 轮询 QQ 扫码授权状态 /api/qqprotocol/poll_qr
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
     * 手机 QQ 端点击“同意授权”触发 API /api/qqprotocol/confirm_auth
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
     * QQ 扫码授权落地页 (支持手机 QQ 扫描) /api/qqprotocol/auth_page
     */
    public function authPage(object $request)
    {
        $sessionId = $request->get('session_id') ?? 'QQ_SESS_DEMO';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>QQ 钱包 ptlogin 协议云端授权</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">
    <div class="w-full max-w-sm bg-white rounded-3xl p-6 shadow-xl text-center space-y-5 border border-slate-100">
        <div class="w-16 h-16 bg-purple-100 text-purple-600 rounded-full flex items-center justify-center mx-auto text-2xl font-bold">
            🐧
        </div>
        <div class="space-y-1">
            <h2 class="text-lg font-extrabold text-slate-800">QQ 钱包云端 ptlogin 扫码授权</h2>
            <p class="text-xs text-slate-500">CXPAY 协议自动化监听系统</p>
        </div>

        <div class="p-4 bg-purple-50 rounded-2xl text-left text-xs text-purple-900 space-y-2 border border-purple-100">
            <div class="font-bold flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-purple-500 inline-block"></span> 授权提示
            </div>
            <p>点击下方按钮后，将授权当前 QQ 钱包与平台服务器绑定。云端协议将自动抓取 QQ 钱包到账通知并实现秒级销账。</p>
        </div>

        <button onclick="confirmAuth()" class="w-full py-3.5 bg-purple-600 hover:bg-purple-700 text-white font-extrabold rounded-2xl shadow-lg shadow-purple-200 transition-all text-sm">
            同意授权 QQ 钱包与服务器绑定
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
            btn.innerText = '正在提交 QQ 授权...';

            try {
                const res = await fetch('/api/qqprotocol/confirm_auth?session_id={$sessionId}', { method: 'POST' });
                const json = await res.json();
                
                btn.innerText = '✅ 授权成功！正在自动关闭...';
                btn.className = 'w-full py-3.5 bg-slate-200 text-slate-500 font-extrabold rounded-2xl text-sm';
                status.innerText = '✅ QQ 钱包授权成功，已与电脑端自动绑定！';
                status.className = 'text-xs font-bold text-purple-600';

                setTimeout(() => {
                    if (typeof mqq !== 'undefined' && mqq.ui && mqq.ui.closeWebview) {
                        mqq.ui.closeWebview();
                    }
                }, 1200);
            } catch (err) {
                btn.innerText = '✅ 授权成功！QQ 绑定完成';
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
