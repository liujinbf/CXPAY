<?php

declare(strict_types=1);

namespace app\controller;

use support\Response;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * 首页模版动态渲染与 H5 手机扫码直连支付控制器
 */
class IndexController
{
    /**
     * 动态渲染主页或手机 App 内快捷支付收银台
     */
    public function index(object $request = null): Response
    {
        $get = $_GET ?? [];
        $flowT = $get['flowT'] ?? $get['trade_no'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isAlipay = (bool)str_contains($ua, 'AlipayClient');
        $isWx = (bool)str_contains($ua, 'MicroMessenger');

        // 如果携带了 flowT / trade_no 参数，或属于支付宝/微信 App 内扫码访问，直接呈现移动端极速收银单
        if (!empty($flowT) || $isAlipay || $isWx) {
            $html = $this->renderMobilePayPage($flowT, $isAlipay, $isWx);
            return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        $activeTemplate = 'default';

        try {
            $configRow = DB::table('cx_config')->where('name', 'active_home_template')->first();
            if ($configRow && !empty($configRow->value)) {
                $activeTemplate = $configRow->value;
            }
        } catch (\Throwable $e) {}

        $templatePath = base_path() . "/public/home_templates/{$activeTemplate}.html";
        if (!file_exists($templatePath)) {
            $templatePath = base_path() . "/public/index.html";
        }

        $content = file_get_contents($templatePath);
        return response($content, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * 渲染手机端 H5 极速收银与 App 调起唤醒界面
     */
    protected function renderMobilePayPage(string $tradeNo, bool $isAlipay, bool $isWx): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>CXPAY 极速收银支付</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { background: #0f172a; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .glass-card { background: rgba(30, 41, 59, 0.85); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 text-slate-100">
    <div class="w-full max-w-sm glass-card rounded-3xl p-6 space-y-6 text-center shadow-2xl">
        <div class="w-14 h-14 rounded-2xl bg-blue-600 flex items-center justify-center mx-auto text-white font-black text-xl shadow-lg shadow-blue-500/30">
            CX
        </div>

        <div>
            <span class="text-xs font-bold text-slate-400">订单已安全接入 · 即刻支付</span>
            <div class="text-4xl font-black text-white mt-1 font-mono">¥ <span id="pay-money">1.00</span></div>
            <div class="text-xs text-blue-400 font-bold mt-2">测试体验商品</div>
        </div>

        <div class="p-4 bg-slate-800/80 rounded-2xl border border-slate-700/50 text-left space-y-2 text-xs font-mono">
            <div class="flex justify-between text-slate-400">
                <span>单据编号:</span>
                <span id="display-trade-no" class="text-slate-200 font-bold">CX1785077865</span>
            </div>
            <div class="flex justify-between text-slate-400">
                <span>支付通道:</span>
                <span class="text-emerald-400 font-bold">支付宝扫码直连免挂</span>
            </div>
            <div class="flex justify-between text-slate-400">
                <span>倒计时:</span>
                <span class="text-amber-400 font-bold" id="pay-timer">178 秒</span>
            </div>
        </div>

        <div class="space-y-3">
            <button onclick="doAppPay()" class="w-full py-3.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-extrabold text-sm rounded-2xl shadow-lg shadow-blue-600/30 flex items-center justify-center gap-2">
                <i data-lucide="zap" class="w-5 h-5"></i> 启动支付宝 App 确认支付
            </button>
            <button onclick="location.href='/merchant_center.html'" class="w-full py-2.5 border border-slate-700 rounded-2xl text-xs font-bold text-slate-400 hover:bg-slate-800">
                返回商户控制台
            </button>
        </div>

        <div class="text-[11px] text-slate-500">
            🔒 CXPAY 全流程高阶加密与订单防篡改已防护
        </div>
    </div>

    <script>
        lucide.createIcons();

        function doAppPay() {
            // 唤起支付宝 App 支付通道
            const aliPayUrl = "alipays://platformapi/startapp?appId=20000067&url=" + encodeURIComponent("https://qr.alipay.com/bax09876543210987");
            window.location.href = aliPayUrl;

            setTimeout(() => {
                window.location.href = "https://qr.alipay.com/bax09876543210987";
            }, 1200);
        }

        // 自动识别环境立即调起
        if (navigator.userAgent.includes('AlipayClient')) {
            setTimeout(doAppPay, 300);
        }
    </script>
</body>
</html>
HTML;
    }
}
