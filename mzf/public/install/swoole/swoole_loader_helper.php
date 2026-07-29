<?php
/**
 * Swoole Compiler Loader Wizard
 * Swoole Compiler Loader 安装助手
 * version : 3.2
 * date    : 2025-06-20
 */
ini_set("display_errors", "On");
error_reporting(E_ALL);
restore_exception_handler();
restore_error_handler();
date_default_timezone_set('Asia/Shanghai');

// Set constants
define('WIZARD_VERSION', '3.2');
define('WIZARD_DEFAULT_LANG', 'zh-cn');
define('WIZARD_OPTIONAL_LANG', 'zh-cn,en');
define('WIZARD_NAME_ZH', 'Swoole Compiler Loader 安装助手');
define('WIZARD_NAME_EN', 'Php Encrypt Swoole Compiler Loader Wizard');
define('WIZARD_DEFAULT_RUN_MODE', 'web');
define('WIZARD_OPTIONAL_RUN_MODE', 'cli,web');
define('WIZARD_DEFAULT_OS', 'linux');
define('WIZARD_OPTIONAL_OS', 'linux,windows');
define('WIZARD_BASE_API', 'https://business.swoole.com/compiler.html');

// Language items
$languages['zh-cn'] = [
    'title' => 'Swoole Compiler Loader 安装助手',
];
$languages['en'] = [
    'title' => 'Php Encrypt Swoole Compiler Loader Wizard',
];
// Set env variable for current environment
$env = [];
// Check os type
$env['os'] = [];
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $env['os']['name'] = "windows";
    $env['os']['raw_name'] = php_uname();
} else {
    $env['os']['name'] = "unix";
    $env['os']['raw_name'] = php_uname();
}
// Check php
$env['php'] = [];
$env['php']['version'] = phpversion();
// Check run mode
$sapi_type = php_sapi_name();
if ( "cli" == $sapi_type) {
    $env['php']['run_mode'] = "cli";
} else {
    $env['php']['run_mode'] = "web";
}
// Check php bit
if (PHP_INT_SIZE == 4) {
    $env['php']['bit'] = 32;
} else {
    $env['php']['bit'] = 64;
}
$env['php']['sapi'] = $sapi_type;
$env['php']['ini_loaded_file'] = php_ini_loaded_file();
$env['php']['ini_scanned_files'] = php_ini_scanned_files();
$env['php']['loaded_extensions'] = get_loaded_extensions();
$env['php']['incompatible_extensions'] = ['xdebug', 'ionCube', 'zend_loader'];
$env['php']['loaded_incompatible_extensions'] = [];
$env['php']['extension_dir'] = ini_get('extension_dir');
// Check incompatible extensions
if (is_array($env['php']['loaded_extensions'])) {
    foreach($env['php']['loaded_extensions'] as $loaded_extension) {
        foreach($env['php']['incompatible_extensions'] as $incompatible_extension) {
            if (strpos(strtolower($loaded_extension), strtolower($incompatible_extension)) !== false) {
                $env['php']['loaded_incompatible_extensions'][] = $loaded_extension;
            }
        }
    }
}
$env['php']['loaded_incompatible_extensions'] = array_unique($env['php']['loaded_incompatible_extensions']);
// Parse System Environment Info
$sysInfo = w_getSysInfo();
// Check php thread safety
$env['php']['raw_thread_safety'] = isset($sysInfo['thread_safety']) ? $sysInfo['thread_safety'] : false;
if (isset($sysInfo['thread_safety'])) {
    $env['php']['thread_safety'] = $sysInfo['thread_safety'] ? '线程安全' : '非线程安全';
} else {
    $env['php']['thread_safety'] = '未知';
}
// 已安装直接跳转首页
$http_type = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')) ? 'https://' : 'http://';
$the_homeurl = $http_type . $_SERVER['HTTP_HOST'];
$is_update = false;
// Check swoole loader installation
if (isset($sysInfo['swoole_loader']) and isset($sysInfo['swoole_loader_version'])) {
    if (!$is_update) {
        echo "<script type='text/javascript'>window.location.href='$the_homeurl';</script>";exit;
    }
    $env['php']['swoole_loader']['status']  = $sysInfo['swoole_loader'] ? "<span style='color: #007bff;'>已安装</span>"
        : '未安装';
    if ($sysInfo['swoole_loader_version'] !== false) {
        $env['php']['swoole_loader']['version'] = "<span style='color: #007bff;'>". $sysInfo['swoole_loader_version'] ."</span>";
    } else {
        $env['php']['swoole_loader']['version'] = '未知';
    }
} else {
    $env['php']['swoole_loader']['status']  = '未安装';
    $env['php']['swoole_loader']['version'] =  '未知';
}
if (extension_loaded('swoole_loader') && !$is_update) {
    echo "<script type='text/javascript'>window.location.href='$the_homeurl';</script>";exit;
}

// 下载 3.2 版本loader（64位）
$download_url=[
    'unix'=>[
        'nosafety'=>[
            '7.2'=>'http://encode.ba9.cn/loader32/swoole_loader72_nts.so',
            '7.3'=>'http://encode.ba9.cn/loader32/swoole_loader73_nts.so',
            '7.4'=>'http://encode.ba9.cn/loader32/swoole_loader74_nts.so',
            '8.0'=>'http://encode.ba9.cn/loader32/swoole_loader80_nts.so',
            '8.1'=>'http://encode.ba9.cn/loader32/swoole_loader81_nts.so',
            '8.2'=>'http://encode.ba9.cn/loader32/swoole_loader82_nts.so',
            '8.3'=>'http://encode.ba9.cn/loader32/swoole_loader83_nts.so',
            '8.4'=>'http://encode.ba9.cn/loader32/swoole_loader84_nts.so',
        ],
        'safety'=>[
            '7.2'=>'http://encode.ba9.cn/loader32/swoole_loader72_zts.so',
            '7.3'=>'http://encode.ba9.cn/loader32/swoole_loader73_zts.so',
            '7.4'=>'http://encode.ba9.cn/loader32/swoole_loader74_zts.so',
            '8.0'=>'http://encode.ba9.cn/loader32/swoole_loader80_zts.so',
            '8.1'=>'http://encode.ba9.cn/loader32/swoole_loader81_zts.so',
            '8.2'=>'http://encode.ba9.cn/loader32/swoole_loader82_zts.so',
            '8.3'=>'http://encode.ba9.cn/loader32/swoole_loader83_zts.so',
            '8.4'=>'http://encode.ba9.cn/loader32/swoole_loader84_zts.so',
        ]
    ],
    'windows'=>[
        'nosafety'=>[
            '7.2'=>'http://encode.ba9.cn/loader32/swoole_loader72_nts.dll',
            '7.3'=>'http://encode.ba9.cn/loader32/swoole_loader73_nts.dll',
            '7.4'=>'http://encode.ba9.cn/loader32/swoole_loader74_nts.dll',
            '8.0'=>'http://encode.ba9.cn/loader32/swoole_loader80_nts.dll',
            '8.1'=>'http://encode.ba9.cn/loader32/swoole_loader81_nts.dll',
            '8.2'=>'http://encode.ba9.cn/loader32/swoole_loader82_nts.dll',
            '8.3'=>'http://encode.ba9.cn/loader32/swoole_loader83_nts.dll',
            '8.4'=>'http://encode.ba9.cn/loader32/swoole_loader84_nts.dll',
        ],
        'safety'=>[
            '7.2'=>'http://encode.ba9.cn/loader32/swoole_loader72_zts.dll',
            '7.3'=>'http://encode.ba9.cn/loader32/swoole_loader73_zts.dll',
            '7.4'=>'http://encode.ba9.cn/loader32/swoole_loader74_zts.dll',
            '8.0'=>'http://encode.ba9.cn/loader32/swoole_loader80_zts.dll',
            '8.1'=>'http://encode.ba9.cn/loader32/swoole_loader81_zts.dll',
            '8.2'=>'http://encode.ba9.cn/loader32/swoole_loader82_zts.dll',
            '8.3'=>'http://encode.ba9.cn/loader32/swoole_loader83_zts.dll',
            '8.4'=>'http://encode.ba9.cn/loader32/swoole_loader84_zts.dll',
        ]
    ],
];

// 当前环境对应版本
$_php_os = $env['os']['name'];
$_php_v = substr($env['php']['version'],0,3);
$_is_safety = (empty($sysInfo['thread_safety'])) ? 'nosafety' : 'safety' ;
$the_os_downurl = $download_url[$_php_os][$_is_safety][$_php_v];
preg_match('/\/([^\/]+\.[a-z]+)[^\/]*$/',$the_os_downurl,$down_name);

// var_dump($down_name[1]);
/**
 *  Web mode
 */
if ('web' == $env['php']['run_mode']) {
    $language = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 4);
    
    if (preg_match("/zh-C/i", $language)) {
        $env['lang'] = "zh-cn";
        $wizard_lang = $env['lang'];
    } else {
        $env['lang'] = "en";
        $wizard_lang = $env['lang'];
    }
    $html = '';
    $html_header = '<!doctype html><html lang="'.$wizard_lang.'">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no"><title>Swoole Compiler 安装向导</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>body{font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;background:linear-gradient(to right,#2196F3,#00BCD4);color:#333;margin:0;padding:0}.container{width:100%;max-width:960px;margin:40px auto;padding:20px;background-color:#ffffff;box-shadow:0 10px 30px rgba(0,0,0,0.1);border-radius:10px;overflow:hidden}header{text-align:center;margin-bottom:30px}header h2{font-size:32px;color:#333;font-weight:600}header p{font-size:18px;color:#777}.card{background:#fff;border-radius:8px;box-shadow:0 4px 10px rgba(0,0,0,0.1);margin-bottom:20px;padding:20px;transition:transform .3s ease-in-out}.card:hover{transform:translateY(-10px)}.card-header{font-size:24px;font-weight:600;color:#2196F3;margin-bottom:15px}.card-body{font-size:16px;color:#555}.card-body ul{list-style:none;padding:0}.card-body li{margin:10px 0}.card-body li span{font-weight:bold;color:#2196F3}.btn{display:inline-block;padding:10px 20px;background-color:#2196F3;color:#fff;border-radius:5px;text-decoration:none;font-weight:bold;transition:background-color .3s ease}.btn:hover{background-color:#1976D2}.btn:active{background-color:#1565C0}footer{text-align:center;margin-top:40px;font-size:14px;color:#777}footer a{color:#2196F3;text-decoration:none}footer a:hover{text-decoration:underline}@media (max-width:768px){.card{margin:10px 10px 20px 10px}.container{border-radius: 0px;margin:0px;padding:0px;font-weight: 500; background: linear-gradient(295deg, #16baaa 12.41%,#16b777 52.55%,#16baaa 89.95%);header h2,header p,footer,footer a{color: #F7F7F7;}header h2{font-size:28px}header p{font-size:16px}.card-header{font-size:22px}.card-body{font-size:14px}.card-body li{font-size:14px}.btn{font-size:14px;padding:8px 15px}footer{font-size:12px}}@media (max-width:480px){header h2{font-size:24px;color: #F7F7F7;}header p{font-size:14px}.card-header{font-size:20px}.card-body{font-size:12px}.card-body li{font-size:12px}.btn{font-size:12px;padding:6px 12px}footer{font-size:10px}}
</style>
</head>
<body>';
    $html_body = '<div class="container">';
    $html_body_nav = '<header>';
    $html_body_nav .= '<h2>Swoole Compiler 3.2 安装向导</h2>';
    $html_body_nav .= '<p>（需要Swoole Compiler加密扩展）</p><p>如果您环境是虚拟主机不支持自定义安装php扩展。</p>';
    $html_body_nav .=  '</header>';
    $html_body_environment = '<div class="card"><div class="card-header"><i class="fas fa-info-circle"></i> 您的环境信息</div><div class="card-body"><ul>';
    
    $html_body_environment .= '<li><span class="list_info">操作系统 : </span>' . $env['os']['raw_name'] . '</li>';
    $html_body_environment .= '<li><span class="list_info">PHP版本 : </span>' . $env['php']['version'] . '</li>';
    $html_body_environment .= '<li><span class="list_info">PHP运行环境 : </span>' . $env['php']['sapi'] . '</li>';
    $html_body_environment .= '<li><span class="list_info">PHP配置文件 : </span>'  . $env['php']['ini_loaded_file'] . '</li>';
    $html_body_environment .= '<li><span class="list_info">PHP扩展安装目录 : </span>' . $env['php']['extension_dir'] . '</li>';
    $html_body_environment .= '<li><span class="list_info">PHP是否线程安全 : </span>' . $env['php']['thread_safety'] . '</li>';
    $html_body_environment .= '<li><span class="list_info">是否安装swoole_loader : </span>' . $env['php']['swoole_loader']['status'] . '</li>';
    //检测swoole版本
    if (isset($sysInfo['swoole_loader']) and $sysInfo['swoole_loader']) {
        $html_body_environment .= '<li><span class="list_info">swoole_loader版本 : </span>' . $env['php']['swoole_loader']['version'] . '</li>';
        if ($is_update) {
            $html_body_environment .= '<span class="list_info" style=" color: red; font-weight: bold; ">（重要）swoole_loader版本过低，需要升级到3.2版本</span>';
        }
    }
    //操作系统32位的不支持
    if ($env['php']['bit'] == 32) {
        $html_body_environment .= '<li><span style="color:red">温馨提示：当前环境使用的PHP为 ' . $env['php']['bit'] . ' 位的PHP，Compiler 目前不支持 Debug 版本或 32 位的PHP，可在 phpinfo() 中查看对应位数，如果误报请忽略此提示</span></li>';
    }
    $html_body_environment .= ' </ul></div></div>';
    // Error infomation
    $html_error = "";
    if (!empty($env['php']['loaded_incompatible_extensions'])) {
        $html_error = '
        <div class="col-12"  style="">
        <h5 class="text-center" style="color:red">错误信息</h5>
        <p class="text-center" style="color:red">%s</p>
    </div>
        ';
        $err_msg = "当前PHP包含与swoole_compiler_loader扩展不兼容的扩展" . implode(',', $env['php']['loaded_incompatible_extensions']) . "，请在PHP配置文件 php.ini 中移除提示不兼容的扩展。然后保持配置重启PHP继续。";
        $html_error = sprintf($html_error, $err_msg);
    }
    // Check Loader Status
    $html_body_loader = '';
    
    if (empty($html_error)) {
        $html_body_loader .= '<div class="card"><div class="card-header"><i class="fas fa-cogs"></i> ';
        if ($is_update) {
            $html_body_loader .= '升级Swoole Loader 扩展</div>';
        }else{
            $html_body_loader .= '安装和配置Swoole Loader 扩展</div>';
        }
        $html_body_loader .= '<div class="card-body"><p>1 - <a class="btn" target="_blank" href="'.$the_os_downurl.'"><i class="fas fa-download"></i> 点击下载 '.$_php_os.' PHP'.$_php_v.' Swoole Loader扩展文件</a></p>';
        if ($is_update) {
            $html_body_loader .= '<p>2 - <strong>升级Swoole Loader</strong></p><p>将下载的Swoole Loader扩展文件（'.$down_name[1].'）重命名为你当前php扩展目录中已经存在的swoole文件名称，然后上传到当前PHP的扩展安装目录中覆盖：<strong style="color: #2196F3;">' . $env['php']['extension_dir'] . '</strong></p>';
        }else{
            $html_body_loader .= '<p>2 - <strong>安装加密拓展</strong></p><p>将下载的Swoole Loader扩展文件（'.$down_name[1].'）上传到当前PHP的扩展安装目录中：<strong style="color: #2196F3;">' . $env['php']['extension_dir'] . '</strong></p>';
        }
        if (!$is_update) {
            $html_body_loader .= '<p>3 - <strong>修改 php.ini 配置</strong>（如已修改配置，请忽略此步骤，不必重复添加）</p><p>';
            $html_body_loader .= '<p>编辑此 PHP 配置文件：<strong style="color: #2196F3;">'.$env['php']['ini_loaded_file'].'</strong><br>在此文件底部结尾处加入如下配置并且保存：';
            if ($env['os']['name'] ==  "windows") {
                $html_body_loader .= '<strong style="color: #2196F3;">extension='.$down_name[1].'</strong><br>注意：需要名称和刚才上传到当前PHP的扩展安装目录中的文件名一致';
            } else {
                $html_body_loader .= '<strong style="color: #2196F3;">extension='.$down_name[1].'</strong><br>注意：需要名称和刚才上传到当前PHP的扩展安装目录中的文件名一致';
            }
        }
        $html_body_loader .= '</p>';
        $html_body_loader .= '<p>4 - <strong>重启 PHP 或者重启服务器刷新页面即可</strong></p>';
        $html_body_loader .= '</div></div>';
    }
    // Body footer
    $html_body_footer = '<footer><p>CopyRight © 2018 - '. date('Y') . ' <a href="javascript:;">
Swoole Compiler</a> 加密扩展安装引导助手</p></footer>';
    $html_body .= $html_body_nav .  $html_body_environment . $html_error . $html_body_loader .  $html_body_footer;
    $html_body .= '</div>';
    // Footer
    $html_footer = '</div></body></html>';
    $html = $html_header .  $html_body . $html_footer;
    echo $html;
}

if ( "cli" == $env['php']['run_mode'] ) {

}
function w_dump($var) {
    if(is_object($var) and $var instanceof Closure) {
        $str    = 'function (';
        $r      = new ReflectionFunction($var);
        $params = array();
        foreach($r->getParameters() as $p) {
            $s = '';
            if($p->isArray()) {
                $s .= 'array ';
            } else if($p->getClass()) {
                $s .= $p->getClass()->name . ' ';
            }
            if($p->isPassedByReference()){
                $s .= '&';
            }
            $s .= '$' . $p->name;
            if($p->isOptional()) {
                $s .= ' = ' . var_export($p->getDefaultValue(), TRUE);
            }
            $params []= $s;
        }
        $str .= implode(', ', $params);
        $str .= '){' . PHP_EOL;
        $lines = file($r->getFileName());
        for($l = $r->getStartLine(); $l < $r->getEndLine(); $l++) {
            $str .= $lines[$l];
        }
        echo $str;
        return;
    } else if(is_array($var)) {
        echo "<xmp class='a-left'>";
        print_r($var);
        echo "</xmp>";
        return;
    } else {
        var_dump($var);
        return;
    }
}
function w_parse_version($version) {
    $versionList = [];
    if (is_string($version)) {
        $rawVersionList = explode('.', $version);
        if (isset($rawVersionList[0])) {
            $versionList[] = $rawVersionList[0];
        }
        if (isset($rawVersionList[1])) {
            $versionList[] = $rawVersionList[1];
        }
    }
    return $versionList;
}
function w_getSysInfo() {
    global $env;
    $sysEnv = [];
    ob_start();
    phpinfo();
    $sysInfo = ob_get_contents();
    ob_end_clean();
    if ($env['php']['run_mode'] == 'cli') {
        $sysInfoList = explode('\n', $sysInfo);
    } else {
        $sysInfoList = explode('</tr>', $sysInfo);
    }
    foreach($sysInfoList as $sysInfoItem) {
        if (preg_match('/thread safety/i', $sysInfoItem)) {
            $sysEnv['thread_safety'] = (preg_match('/(enabled|yes)/i', $sysInfoItem) != 0);
        }
        if (preg_match('/swoole_loader support/i', $sysInfoItem)) {
            $sysEnv['swoole_loader'] = (preg_match('/(enabled|yes)/i', $sysInfoItem) != 0);
        }
        if (preg_match('/swoole_loader version/i', $sysInfoItem)) {
            preg_match('/\d+.\d+.\d+/s', $sysInfoItem, $match);
            $sysEnv['swoole_loader_version'] = isset($match[0]) ? $match[0] : false;
        }
    }
    if (!isset($sysEnv['swoole_loader'])) {
        $sysEnv['swoole_loader'] = extension_loaded('swoole_loader');
    }
    if (!isset($sysEnv['swoole_loader_version']) && function_exists('swoole_loader_version')) {
        $sysEnv['swoole_loader_version'] = swoole_loader_version();
    }
    return $sysEnv;
}