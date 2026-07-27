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

if (!function_exists('response')) {
    function response($body = '', $status = 200, $headers = []) {
        return new class($body) {
            private $body;
            public function __construct($b) { $this->body = $b; }
            public function rawBody() { return $this->body; }
            public function __toString() { return $this->body; }
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

// 0.9 一键安装向导路由 (/install)
if ($uriPath === '/install' || $uriPath === '/install/' || $uriPath === '/install/index.html') {
    $lockFile = __DIR__ . '/install.lock';
    if (file_exists($lockFile)) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<div style="background:#0f172a;color:#f3f4f6;padding:40px;text-align:center;font-family:sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;">
            <div style="font-size:48px;margin-bottom:16px;">🛡️</div>
            <h2 style="font-size:24px;font-weight:bold;margin-bottom:8px;">系统已被安全锁定</h2>
            <p style="color:#94a3b8;font-size:14px;max-width:480px;line-height:1.6;">当前系统已安装完成并生成了 <code>install.lock</code> 安全锁文件。如需重新安装，请在服务器中删除根目录下的 <code>install.lock</code> 锁文件。</p>
            <a href="/" style="margin-top:24px;display:inline-block;padding:10px 24px;background:#0284c7;color:#fff;border-radius:12px;text-decoration:none;font-weight:bold;font-size:14px;">返回网站首页</a>
        </div>';
        exit;
    }
    $installFile = __DIR__ . '/public/install/index.html';
    if (file_exists($installFile)) {
        header('Content-Type: text/html; charset=utf-8');
        echo file_get_contents($installFile);
        exit;
    }
}
if (str_starts_with($uriPath, '/api/install/')) {
    header('Content-Type: application/json; charset=utf-8');
    $ctrl = new \app\controller\api\InstallController();
    $req = new class($uriPath) {
        private $path;
        public function __construct($p) { $this->path = $p; }
        public function get($k = null) { return $k ? ($_GET[$k] ?? null) : $_GET; }
        public function post($k = null) { return $k ? ($_POST[$k] ?? null) : $_POST; }
        public function path() { return $this->path; }
    };

    if (str_contains($uriPath, 'check')) {
        $res = $ctrl->check($req);
    } elseif (str_contains($uriPath, 'test_db')) {
        $res = $ctrl->testDb($req);
    } else {
        $res = $ctrl->execute($req);
    }

    echo is_object($res) && method_exists($res, 'rawBody') ? $res->rawBody() : (string)$res;
    exit;
}

// 1. 商户开放 API 文档映射 (/doc /doc.html)
if ($uriPath === '/doc' || $uriPath === '/doc.html') {
    $docFile = __DIR__ . '/public/doc.html';
    if (file_exists($docFile)) {
        header('Content-Type: text/html; charset=utf-8');
        echo file_get_contents($docFile);
        exit;
    }
}

// 1.46 测试支付通道异步回调 API (/api/test_notify)
if (str_contains($uriPath, '/api/test_notify')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'success';
    exit;
}

// 1.47 上游支付通道异步回调 API (/notify/{cType})
if (str_starts_with($uriPath, '/notify/')) {
    header('Content-Type: text/plain; charset=utf-8');
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

    $parts = explode('/', trim($uriPath, '/'));
    $cType = $parts[1] ?? 'alipay_official';
    $ctrl = new \app\controller\notify\NotifyController();
    $req = new class($uriPath) {
        private $path;
        public function __construct($p) { $this->path = $p; }
        public function get($k = null) { return $k ? ($_GET[$k] ?? null) : $_GET; }
        public function post($k = null) { return $k ? ($_POST[$k] ?? null) : $_POST; }
        public function path() { return $this->path; }
    };
    $res = $ctrl->index($req, $cType);
    echo is_object($res) && method_exists($res, 'rawBody') ? $res->rawBody() : (string)$res;
    exit;
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
    } elseif (str_contains($uriPath, 'create_test')) {
        $res = $ctrl->createTest($req);
    } elseif (str_contains($uriPath, 'mock_pay')) {
        $res = $ctrl->mockPay($req);
    } else {
        $res = json_encode(['code' => -1, 'msg' => '未知 API']);
    }

    echo is_object($res) && method_exists($res, 'rawBody') ? $res->rawBody() : (string)$res;
    exit;
}

// 1.45 订单收银台公开查询 API (/api/order/query)
if (str_contains($uriPath, '/api/order/query')) {
    header('Content-Type: application/json; charset=utf-8');
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

    $ctrl = new \app\controller\api\OrderController();
    $req = new class($uriPath) {
        private $path;
        public function __construct($p) { $this->path = $p; }
        public function get($k = null) { return $k ? ($_GET[$k] ?? null) : $_GET; }
        public function post($k = null) { return $k ? ($_POST[$k] ?? null) : $_POST; }
        public function path() { return $this->path; }
    };

    $res = $ctrl->query($req);
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

// 1.9 动态主页与手机端 H5 快捷支付路由 ( / )
if ($uriPath === '/' || $uriPath === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    $indexCtrl = new \app\controller\IndexController();
    $res = $indexCtrl->index();
    echo is_object($res) && method_exists($res, 'rawBody') ? $res->rawBody() : (string)$res;
    exit;
}

// 2. 易支付标准下单网关协议 (/submit.php & /mapi.php)
if (str_contains($uriPath, 'submit.php') || str_contains($uriPath, 'mapi.php')) {
    $amount  = $_GET['money'] ?? $_POST['money'] ?? '1.00';
    $subject = $_GET['name'] ?? $_POST['name'] ?? 'CXPAY 极速测试订单';
    $type    = $_GET['type'] ?? $_POST['type'] ?? 'alipay';
    $pid     = $_GET['pid'] ?? $_POST['pid'] ?? '1000';
    $outTradeNo = $_GET['out_trade_no'] ?? $_POST['out_trade_no'] ?? ('DEMO' . time() . mt_rand(100, 999));
    $tradeNo = 'CX' . date('YmdHis') . sprintf('%04d', mt_rand(1, 9999));

    try {
        $dbConfig = require __DIR__ . '/config/database.php';
        if ($dbConfig && class_exists('Illuminate\Database\Capsule\Manager')) {
            $capsule = new \Illuminate\Database\Capsule\Manager();
            foreach ($dbConfig['connections'] as $name => $conn) {
                $capsule->addConnection($conn, $name);
            }
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            \app\model\Order::create([
                'merchant_id'  => (int)$pid,
                'out_trade_no' => $outTradeNo,
                'trade_no'     => $tradeNo,
                'channel_id'   => 1,
                'pay_type'     => $type,
                'amount'       => (float)$amount,
                'price'        => (float)$amount,
                'subject'      => $subject,
                'notify_url'   => 'https://cxpay.onrender.com/demo_notify',
                'return_url'   => 'https://cxpay.onrender.com/merchant_center.html',
                'status'       => 0,
                'create_time'  => time(),
                'expire_time'  => time() + 300,
            ]);
        }
    } catch (\Throwable $e) {}

    if (str_contains($uriPath, 'mapi')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'code' => 1,
            'msg'  => '下单成功',
            'trade_no' => $tradeNo,
            'payurl'   => "/cashier/index.html?trade_no={$tradeNo}"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header("Location: /cashier/index.html?trade_no={$tradeNo}");
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
