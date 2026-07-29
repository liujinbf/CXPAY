<?php

use think\facade\Route;

// APP监控(Peak助手) 写死的品牌配置路径 → 返回 {"data":{...}}（供 APP 直连，无需登录/请求头）
Route::get('PluginStore/AppConfig/Get', '\app\api\controller\Appasst@config');
Route::get('pluginstore/AppConfig/Get', '\app\api\controller\Appasst@config');
