<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

// [ 应用入口文件 ]
namespace think;

// 公开支付接口应用（对外，付款人/商户/挂机APP 直接访问，不会带 server 标记）
// 必须绕过下方"访客访问前端→跳 SPA"的逻辑，否则收款/回调/推送会被重定向到首页
$__uriPath  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$__firstSeg = strtolower(explode('/', ltrim($__uriPath, '/'))[0] ?? '');
$__payApps  = ['gateway', 'notify', 'openapi'];
// 挂机端固定短路由（见 route/app.php），第三方客户端直连，须一并放行
$__payShorts = ['asstheart', 'asstpush', 'appheart', 'apppush', 'winheart', 'winpush', 'submit.php', 'mapi.php', 'api.php', 'pluginstore'];
$__isPayApp = in_array($__firstSeg, $__payApps, true) || in_array($__firstSeg, $__payShorts, true);

// PeakWin PC 挂机软件写死短路由 /Openapi/WeChat_Pc/{CheckUser,SubmitSid,ObtainTtSwitch}
// → openapi/WeChatPc/{checkUser,submitSid,obtainTtSwitch}（软件端路径大小写固定，此处规范化后再交 TP 分发）
if (preg_match('#^/openapi/wechat_pc/(checkuser|submitsid|obtainttswitch)$#i', $__uriPath, $__m)) {
    $__pcActionMap = ['checkuser' => 'checkUser', 'submitsid' => 'submitSid', 'obtainttswitch' => 'obtainTtSwitch'];
    $__pcTarget    = '/openapi/WeChatPc/' . $__pcActionMap[strtolower($__m[1])];
    $_GET['s']     = $__pcTarget;
    $_REQUEST['s'] = $__pcTarget;
    $_SERVER['PATH_INFO']   = $__pcTarget;
    $_SERVER['REQUEST_URI'] = $__pcTarget . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
    $__isPayApp = true;
}

// Peak小助手 写死短路由 /asstHeart /asstPush → openapi/Asst/{heart,push}（原伪静态迁入，走完整 openapi 分发）
if (preg_match('#^/(asstHeart|asstPush)$#i', $__uriPath, $__m2)) {
    $__asstMap = ['asstheart' => '/openapi/Asst/heart', 'asstpush' => '/openapi/Asst/push'];
    $__asstTarget = $__asstMap[strtolower($__m2[1])];
    $_GET['s']     = $__asstTarget;
    $_REQUEST['s'] = $__asstTarget;
    $_SERVER['PATH_INFO']   = $__asstTarget;
    $_SERVER['REQUEST_URI'] = $__asstTarget . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
    $__isPayApp = true;
}

$server = $__isPayApp || isset($_REQUEST['server']) || isset($_SERVER['HTTP_SERVER']) || substr($_SERVER['REQUEST_URI'], 1, 9) == 'index.php' || $_SERVER['REQUEST_METHOD'] == 'OPTIONS';
if (!$server) {
    /*
     * 用户访问前端
     * 不在tp加载后判断，为了安全的使用 exit()（常驻内存运行时不走本文件）
     */
    $rootPath = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR;

    // 安装检测-s
    if (!is_file($rootPath . 'install.lock') && is_file($rootPath . 'install' . DIRECTORY_SEPARATOR . 'index.html')) {
        header("location:" . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR);
        exit();
    }
    // 安装检测-e

    // 未授权拦截：站点未授权时，首页及任何前后台页面直接显示「系统未授权」-s
    $__unauthFlag = $rootPath . '..' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cloud_unauth.flag';
    $__unauthPage = $rootPath . '..' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'unauthorized.html';
    if (is_file($__unauthFlag)) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(403);
        if (is_file($__unauthPage)) {
            readfile($__unauthPage);
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>系统未授权</title>'
                . '<div style="font-family:sans-serif;text-align:center;padding:16vh 20px;color:#1f2733">'
                . '<div style="font-size:44px">🔒</div><h1 style="font-size:22px">系统未授权</h1>'
                . '<p style="color:#8a94a6">本站授权已到期或未通过验证，所有页面已暂停访问。请联系服务商开通授权后自动恢复。</p></div>';
        }
        exit();
    }
    // 未授权拦截-e

    // 检测是否已编译前端（如果存在 index.html，则直接输出，不重定向到 /index.html，保持干净 URL）-s
    if (is_file($rootPath . 'index.html')) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($rootPath . 'index.html');
        exit();
    }
    // 检测是否已编译前端-e
}

require __DIR__ . '/../vendor/autoload.php';

// 执行HTTP应用并响应
$http = (new App())->http;

$response = $http->run();

$response->send();

$http->end($response);
