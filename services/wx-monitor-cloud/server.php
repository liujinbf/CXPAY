<?php

declare(strict_types=1);

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;
use WxMonitorCloud\Authenticator;
use WxMonitorCloud\CloudApplication;
use WxMonitorCloud\Database;
use WxMonitorCloud\OutboxDispatcher;
use WxMonitorCloud\SecretVault;

$autoload = is_file(__DIR__ . '/vendor/autoload.php')
    ? __DIR__ . '/vendor/autoload.php'
    : dirname(__DIR__, 2) . '/vendor/autoload.php';
require $autoload;

$runtimeDirectory = __DIR__ . '/runtime';
if (!is_dir($runtimeDirectory) && !mkdir($runtimeDirectory, 0750, true) && !is_dir($runtimeDirectory)) {
    throw new RuntimeException('无法创建云监控运行目录');
}
$dsn = (string)(getenv('WXMC_DSN') ?: 'sqlite:' . $runtimeDirectory . '/wx-monitor-cloud.sqlite');
$masterKey = (string)getenv('WXMC_MASTER_KEY');
$listen = (string)(getenv('WXMC_LISTEN') ?: 'http://0.0.0.0:8787');
$databaseUser = getenv('WXMC_DB_USER');
$databasePassword = getenv('WXMC_DB_PASSWORD');
$workerCount = (int)(getenv('WXMC_WORKER_COUNT') ?: 1);
if ($workerCount < 1 || $workerCount > 32) {
    throw new RuntimeException('WXMC_WORKER_COUNT 必须在 1 到 32 之间');
}
if (str_starts_with(strtolower($dsn), 'sqlite:') && $workerCount !== 1) {
    throw new RuntimeException('SQLite 模式仅支持单 Worker；多 Worker 请切换 MySQL');
}

$worker = new Worker($listen);
$worker->name = 'wx-monitor-cloud-api';
$worker->count = $workerCount;
$application = null;
$worker->onWorkerStart = static function () use (
    &$application,
    $dsn,
    $databaseUser,
    $databasePassword,
    $masterKey
): void {
    // PDO 连接必须在 Worker 进程内创建，不能由多个 fork 后的进程共享。
    $pdo = Database::connect(
        $dsn,
        $databaseUser === false || $databaseUser === '' ? null : (string)$databaseUser,
        $databasePassword === false ? null : (string)$databasePassword
    );
    $vault = new SecretVault($masterKey);
    $application = new CloudApplication($pdo, new Authenticator($pdo, $vault));
    $dispatcher = new OutboxDispatcher($pdo, $vault);
    Timer::add(2.0, static function () use ($dispatcher): void {
        try {
            $dispatcher->dispatchDue();
        } catch (Throwable $e) {
            error_log('[WXMC Outbox] ' . $e->getMessage());
        }
    });
};
$worker->onMessage = static function (TcpConnection $connection, Request $request) use (&$application): void {
    if (!$application instanceof CloudApplication) {
        $connection->send(new Response(503, ['Content-Type' => 'application/json'], '{"message":"服务尚未就绪"}'));
        return;
    }
    $result = $application->handle(
        $request->method(),
        $request->path(),
        (array)$request->header(),
        $request->rawBody()
    );
    $connection->send(new Response($result->status, $result->headers, $result->body));
};

Worker::runAll();
