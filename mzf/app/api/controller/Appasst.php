<?php

namespace app\api\controller;

use app\admin\controller\pay\Plugin;

/**
 * APP监控（Peak助手）品牌配置对 APP 的公开接口。
 * 由 route/app.php 定义路由 /PluginStore/AppConfig/Get 直连，返回老系统一致的裸格式：
 *   { "data": { name, logo, title, kfqq, notice, qunname, qunlink } }
 * 无需登录/请求头（public/index.php 已将 pluginstore 段放行，跳过 SPA）。
 */
class Appasst
{
    public function config()
    {
        return json(['data' => Plugin::readAppConfig()])
            ->header([
                'Access-Control-Allow-Origin'  => '*',
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            ]);
    }
}
