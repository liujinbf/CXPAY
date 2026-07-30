<?php

declare(strict_types=1);

namespace app\controller;

use Illuminate\Database\Capsule\Manager as DB;
use support\Request;
use Throwable;

/**
 * 首页模板入口。
 */
class IndexController
{
    public function index(Request $request)
    {
        $lockFile = (string)config('app.install_lock', base_path() . '/install.lock');
        if (!file_exists($lockFile)) {
            return redirect('/install/index.html');
        }

        $tradeNo = trim((string)($request->get('trade_no') ?? $request->get('flowT') ?? ''));
        if ($tradeNo !== '') {
            if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $tradeNo)) {
                return response('订单号格式不正确', 400);
            }
            return redirect('/cashier/index.html?trade_no=' . rawurlencode($tradeNo));
        }

        $activeTemplate = 'default';
        try {
            $configured = (string)(DB::table('cx_config')
                ->where('name', 'active_home_template')
                ->value('value') ?? '');
            if (preg_match('/^[A-Za-z0-9_-]{1,50}$/', $configured)) {
                $activeTemplate = $configured;
            }
        } catch (Throwable) {
            // 数据库暂不可用时使用默认首页。
        }

        $templatePath = base_path() . "/public/home_templates/{$activeTemplate}.html";
        if (!is_file($templatePath)) {
            $templatePath = base_path() . '/public/index.html';
        }
        $content = file_get_contents($templatePath);
        return response($content !== false ? $content : '首页模板读取失败', $content !== false ? 200 : 500, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }
}
