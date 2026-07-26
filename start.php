<?php
/**
 * CXPAY Webman / Workerman 常驻高并发进程启动入口文件
 */

use Workerman\Worker;
use Webman\Config;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

if (!class_exists('Webman\App')) {
    define('BASE_PATH', __DIR__);
    
    // 初始化应用全局路径与环境
    if (file_exists(__DIR__ . '/config/app.php')) {
        Config::load(__DIR__ . '/config');
    }

    // 默认 HTTP Worker 服务监听 8787 端口
    $worker = new Worker("http://0.0.0.0:8787");
    $worker->count = 4;
    $worker->name  = 'CXPAY-WebmanWorker';

    $worker->onWorkerStart = function ($worker) {
        echo "===========================================================\n";
        echo "🚀 CXPAY 商业级聚合支付引擎 (Webman Worker) 启动成功！\n";
        echo "📌 监听服务端口: http://0.0.0.0:8787\n";
        echo "===========================================================\n";

        // 引导数据库与 Redis 关联绑定
        if (class_exists('support\DatabaseBootstrap')) {
            support\DatabaseBootstrap::start($worker);
        }
    };

    $worker->onMessage = function ($connection, $request) {
        $uri = $request->path();
        
        // 易支付网关转发
        if (str_contains($uri, 'submit.php') || str_contains($uri, 'mapi.php')) {
            $controller = new \app\controller\gateway\SubmitController();
            $res = $controller->submit($request);
            $connection->send($res);
            return;
        }

        // 一键安装向导 API
        if ($uri === '/install') {
            $content = file_get_contents(base_path() . '/public/install/index.html');
            $connection->send(response($content, 200, ['Content-Type' => 'text/html; charset=utf-8']));
            return;
        }

        if ($uri === '/api/install/execute') {
            $controller = new \app\controller\api\InstallController();
            $res = $controller->execute($request);
            $connection->send($res);
            return;
        }

        // 商户 API
        if ($uri === '/api/merchant/login') {
            $controller = new \app\controller\api\MerchantApiController();
            $connection->send(response($controller->login($request), 200, ['Content-Type' => 'application/json; charset=utf-8']));
            return;
        }

        // 默认静态文件与全功能处理
        $filePath = base_path() . '/public' . $uri;
        if (file_exists($filePath) && !is_dir($filePath)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'html' => 'text/html; charset=utf-8',
                'css'  => 'text/css; charset=utf-8',
                'js'   => 'application/javascript; charset=utf-8',
                'json' => 'application/json; charset=utf-8',
            ];
            $header = ['Content-Type' => $mimeTypes[$ext] ?? 'application/octet-stream'];
            $connection->send(response(file_get_contents($filePath), 200, $header));
            return;
        }

        $controller = new \app\controller\IndexController();
        $connection->send($controller->index());
    };

    Worker::runAll();
}
