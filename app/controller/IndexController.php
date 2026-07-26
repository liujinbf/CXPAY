<?php

declare(strict_types=1);

namespace app\controller;

use support\Response;
use illuminate\database\capsule\manager as DB;

/**
 * 首页模版动态渲染控制器
 */
class IndexController
{
    /**
     * 动态从数据库 cx_config 读取 active_home_template 并渲染指定主页
     */
    public function index(): Response
    {
        $activeTemplate = 'default';

        try {
            // 从数据库获取保存的主页模版名称 (default / tech / minimal)
            $configRow = DB::table('cx_config')->where('name', 'active_home_template')->first();
            if ($configRow && !empty($configRow->value)) {
                $activeTemplate = $configRow->value;
            }
        } catch (\Throwable $e) {
            // 若数据库尚未连接则兜底使用 default 模版
        }

        $templatePath = base_path() . "/public/home_templates/{$activeTemplate}.html";
        if (!file_exists($templatePath)) {
            $templatePath = base_path() . "/public/index.html";
        }

        $content = file_get_contents($templatePath);
        return response($content, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
