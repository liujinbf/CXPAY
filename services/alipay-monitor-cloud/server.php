<?php

declare(strict_types=1);

use AlipayMonitorCloud\Application;
use AlipayMonitorCloud\Database;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

$autoload = is_file(__DIR__ . '/vendor/autoload.php')
    ? __DIR__ . '/vendor/autoload.php'
    : dirname(__DIR__, 2) . '/vendor/autoload.php';
require $autoload;

$masterKey = getenv('AMC_MASTER_KEY') ?: '';
$dsn       = getenv('AMC_DSN') ?: 'sqlite:' . __DIR__ . '/alipay-monitor-cloud.sqlite';
$listen    = getenv('AMC_LISTEN') ?: 'http://127.0.0.1:8788';

if ($masterKey === '') {
    echo "错误：未设置 AMC_MASTER_KEY 环境变量！\n";
    echo "请运行: php -r \"echo base64_encode(random_bytes(32)), PHP_EOL;\" 生成主密钥。\n";
    exit(1);
}

$database = new Database($dsn);
$app = new Application($database, $masterKey);

$worker = new Worker($listen);
$worker->name = 'alipay-monitor-cloud';
$worker->count = 2;

$worker->onMessage = static function ($connection, Request $request) use ($app): void {
    $method  = $request->method();
    $path    = $request->path();
    $headers = $request->header();
    $body    = (string)$request->rawBody();

    [$status, $data] = $app->handle($method, $path, $headers, $body);

    $response = new Response(
        $status,
        ['Content-Type' => 'application/json; charset=utf-8'],
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $connection->send($response);
};

Worker::runAll();
