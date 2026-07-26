<?php
// 本地与 Docker 镜像 Web 路由支持服务
// 监听端口：$PORT / 8787

if (!function_exists('base_path')) {
    function base_path($path = '') {
        return __DIR__ . ($path ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('json')) {
    function json($data, $options = JSON_UNESCAPED_UNICODE) {
        $body = is_string($data) ? $data : json_encode($data, $options);
        return new class($body) {
            private $body;
            public function __construct($b) { $this->body = $b; }
            public function rawBody() { return $this->body; }
            public function __toString() { return $this->body; }
            public function withStatus($s) { return $this; }
        };
    }
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

$rawUri  = $_SERVER['REQUEST_URI'] ?? '/';
$uriPath = parse_url($rawUri, PHP_URL_PATH) ?: '/';

// 1. 商户开放 API 文档映射 (/doc /doc.html)
if ($uriPath === '/doc' || $uriPath === '/doc.html') {
    $docFile = __DIR__ . '/public/doc.html';
    if (file_exists($docFile)) {
        header('Content-Type: text/html; charset=utf-8');
        echo file_get_contents($docFile);
        exit;
    }
}

// 1.5 商户通道管理 REST API 接口
if (str_starts_with($uriPath, '/api/merchant/channel/')) {
    header('Content-Type: application/json; charset=utf-8');
    
    // 初始化数据库关联
    try {
        $dbConfig = require __DIR__ . '/config/database.php';
        if ($dbConfig && class_exists('Illuminate\Database\Capsule\Manager')) {
            $capsule = new \Illuminate\Database\Capsule\Manager();
            foreach ($dbConfig['connections'] as $name => $conn) {
                $capsule->addConnection($conn, $name);
            }
            $capsule->setAsGlobal();
            $capsule->bootEloquent();
        }
    } catch (\Throwable $e) {}

    $ctrl = new \app\controller\api\MerchantChannelController();
    $req = new class($uriPath) {
        private $path;
        public function __construct($p) { $this->path = $p; }
        public function get($k = null) { return $k ? ($_GET[$k] ?? null) : $_GET; }
        public function post($k = null) { return $k ? ($_POST[$k] ?? null) : $_POST; }
        public function path() { return $this->path; }
    };

    if (str_contains($uriPath, 'list')) {
        $res = $ctrl->list($req);
    } elseif (str_contains($uriPath, 'save')) {
        $res = $ctrl->save($req);
    } elseif (str_contains($uriPath, 'toggle')) {
        $res = $ctrl->toggle($req);
    } elseif (str_contains($uriPath, 'delete')) {
        $res = $ctrl->delete($req);
    } elseif (str_contains($uriPath, 'drivers')) {
        $res = $ctrl->drivers($req);
    } else {
        $res = json_encode(['code' => -1, 'msg' => '未知 API']);
    }

    echo is_object($res) && method_exists($res, 'rawBody') ? $res->rawBody() : (string)$res;
    exit;
}

// 1.5 微信小账本/收款单 扫码登录授权免挂 API
if (str_contains($uriPath, '/api/wxprotocol/')) {
    $wxCtrl = new \app\controller\api\WeChatProtocolAdminController();
    $req = new class($uriPath) {
        private $path;
        public function __construct($p) { $this->path = $p; }
        public function get($k = null) { return $k ? ($_GET[$k] ?? null) : $_GET; }
        public function post($k = null) { return $k ? ($_POST[$k] ?? null) : $_POST; }
        public function path() { return $this->path; }
    };

    if (str_contains($uriPath, 'auth_page')) {
        header('Content-Type: text/html; charset=utf-8');
        $res = $wxCtrl->authPage($req);
    } elseif (str_contains($uriPath, 'confirm_auth')) {
        header('Content-Type: application/json; charset=utf-8');
        $res = $wxCtrl->confirmAuth($req);
    } elseif (str_contains($uriPath, 'login_qr')) {
        header('Content-Type: application/json; charset=utf-8');
        $res = $wxCtrl->getLoginQr();
    } else {
        header('Content-Type: application/json; charset=utf-8');
        $res = $wxCtrl->pollQr($req);
    }

    if (is_object($res) && method_exists($res, 'rawBody')) {
        echo $res->rawBody();
    } else {
        echo is_array($res) ? json_encode($res, JSON_UNESCAPED_UNICODE) : (string)$res;
    }
    exit;
}

// 1.6 支付宝 AppAuth 扫码登录授权免挂 API
if (str_contains($uriPath, '/api/alipay/')) {
    $aliCtrl = new \app\controller\api\AlipayProtocolAdminController();
    $req = new class($uriPath) {
        private $path;
        public function __construct($p) { $this->path = $p; }
        public function get($k = null) { return $k ? ($_GET[$k] ?? null) : $_GET; }
        public function post($k = null) { return $k ? ($_POST[$k] ?? null) : $_POST; }
        public function path() { return $this->path; }
    };

    if (str_contains($uriPath, 'auth_page')) {
        header('Content-Type: text/html; charset=utf-8');
        $res = $aliCtrl->authPage($req);
    } elseif (str_contains($uriPath, 'confirm_auth')) {
        header('Content-Type: application/json; charset=utf-8');
        $res = $aliCtrl->confirmAuth($req);
    } elseif (str_contains($uriPath, 'login_qr')) {
        header('Content-Type: application/json; charset=utf-8');
        $res = $aliCtrl->getLoginQr();
    } else {
        header('Content-Type: application/json; charset=utf-8');
        $res = $aliCtrl->pollQr($req);
    }

    if (is_object($res) && method_exists($res, 'rawBody')) {
        echo $res->rawBody();
    } else {
        echo is_array($res) ? json_encode($res, JSON_UNESCAPED_UNICODE) : (string)$res;
    }
    exit;
}

// 1.7 QQ 钱包 ptlogin 扫码登录授权免挂 API
if (str_contains($uriPath, '/api/qqprotocol/')) {
    $qqCtrl = new \app\controller\api\QQProtocolAdminController();
    $req = new class($uriPath) {
        private $path;
        public function __construct($p) { $this->path = $p; }
        public function get($k = null) { return $k ? ($_GET[$k] ?? null) : $_GET; }
        public function post($k = null) { return $k ? ($_POST[$k] ?? null) : $_POST; }
        public function path() { return $this->path; }
    };

    if (str_contains($uriPath, 'auth_page')) {
        header('Content-Type: text/html; charset=utf-8');
        $res = $qqCtrl->authPage($req);
    } elseif (str_contains($uriPath, 'confirm_auth')) {
        header('Content-Type: application/json; charset=utf-8');
        $res = $qqCtrl->confirmAuth($req);
    } elseif (str_contains($uriPath, 'login_qr')) {
        header('Content-Type: application/json; charset=utf-8');
        $res = $qqCtrl->getLoginQr();
    } else {
        header('Content-Type: application/json; charset=utf-8');
        $res = $qqCtrl->pollQr($req);
    }

    if (is_object($res) && method_exists($res, 'rawBody')) {
        echo $res->rawBody();
    } else {
        echo is_array($res) ? json_encode($res, JSON_UNESCAPED_UNICODE) : (string)$res;
    }
    exit;
}

// 1.8 支付宝/通道防掉线心跳检测 API
if (str_contains($uriPath, '/api/channel/keepalive')) {
    header('Content-Type: application/json; charset=utf-8');
    $kaCtrl = new \app\controller\api\ChannelKeepAliveController();
    $res = $kaCtrl->keepalive();
    echo is_array($res) ? json_encode($res, JSON_UNESCAPED_UNICODE) : (string)$res;
    exit;
}

// 2. 易支付标准下单网关协议 (/submit.php & /mapi.php)
if (str_contains($uriPath, 'submit.php') || str_contains($uriPath, 'mapi.php')) {
    $amount  = $_GET['money'] ?? $_POST['money'] ?? '1.00';
    $subject = $_GET['name'] ?? $_POST['name'] ?? 'CXPAY 极速测试订单';
    $tradeNo = 'CX' . date('YmdHis') . sprintf('%04d', mt_rand(1, 9999));

    $html = file_get_contents(__DIR__ . '/public/cashier/index.html');
    
    // 替换为动态下单数据
    $html = str_replace(
        ['"1.00"', '"测试应用 - VIP会员购买"', '"CX" + Date.now()'],
        ['"' . number_format((float)$amount, 2, '.', '') . '"', '"' . addslashes($subject) . '"', '"' . $tradeNo . '"'],
        $html
    );
    
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

// 3. 静态文件与目录 index.html 自动映射
$filePath = __DIR__ . '/public' . $uriPath;

if (file_exists($filePath)) {
    if (is_dir($filePath)) {
        $indexPath = rtrim($filePath, '/') . '/index.html';
        if (file_exists($indexPath)) {
            header('Content-Type: text/html; charset=utf-8');
            echo file_get_contents($indexPath);
            exit;
        }
    } else {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'html' => 'text/html; charset=utf-8',
            'css'  => 'text/css; charset=utf-8',
            'js'   => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon'
        ];
        if (isset($mimeTypes[$ext])) {
            header('Content-Type: ' . $mimeTypes[$ext]);
        }
        echo file_get_contents($filePath);
        exit;
    }
}

// 4. 默认主页映射
if ($uriPath === '/' || $uriPath === '/index.html') {
    $homePath = __DIR__ . '/public/index.html';
    if (file_exists($homePath)) {
        header('Content-Type: text/html; charset=utf-8');
        echo file_get_contents($homePath);
        exit;
    }
}

// 5. 兜底重定向至收银台
header('Location: /submit.php?pid=1000&type=alipay&money=99.00&name=VIP高级订阅套餐&out_trade_no=' . time());
