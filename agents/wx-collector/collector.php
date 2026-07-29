<?php

declare(strict_types=1);

use Workerman\Timer;
use Workerman\Worker;
use WxCollector\CloudClient;
use WxCollector\CollectorRunner;
use WxCollector\AlipayWebProviderAdapter;
use WxCollector\EncryptedFileStateStore;
use WxCollector\ProviderAdapterInterface;
use WxCollector\UnavailableProviderAdapter;

$autoload = is_file(__DIR__ . '/vendor/autoload.php')
    ? __DIR__ . '/vendor/autoload.php'
    : dirname(__DIR__, 2) . '/vendor/autoload.php';
require $autoload;

$collectorId = trim((string)getenv('WXCOLLECTOR_ID'));
$secret = (string)getenv('WXCOLLECTOR_SECRET');
$cloudUrl = rtrim((string)getenv('WXCOLLECTOR_CLOUD_URL'), '/');
$interval = max(1.0, min(30.0, (float)(getenv('WXCOLLECTOR_INTERVAL') ?: 2.0)));
$allowHttp = filter_var(getenv('WXCOLLECTOR_ALLOW_HTTP') ?: false, FILTER_VALIDATE_BOOL);
$adapterClass = trim((string)(getenv('WXCOLLECTOR_ADAPTER_CLASS') ?: UnavailableProviderAdapter::class));

if (!class_exists($adapterClass)) {
    throw new RuntimeException("采集器适配器类不存在: {$adapterClass}");
}
$adapter = $adapterClass === AlipayWebProviderAdapter::class
    ? new AlipayWebProviderAdapter(new EncryptedFileStateStore(
        (string)(getenv('ALIC_STATE_DIR') ?: __DIR__ . '/runtime/alipay-sessions'),
        (string)getenv('ALIC_MASTER_KEY')
    ))
    : new $adapterClass();
if (!$adapter instanceof ProviderAdapterInterface) {
    throw new RuntimeException('采集器适配器必须实现 ProviderAdapterInterface');
}

$cloud = new CloudClient($cloudUrl, $collectorId, $secret, $allowHttp);
$runner = new CollectorRunner(
    $collectorId,
    $cloud,
    $adapter,
    static fn(string $message) => error_log('[Payment Collector] ' . $message)
);

$worker = new Worker();
$worker->name = $adapter instanceof AlipayWebProviderAdapter
    ? 'alipay-web-collector'
    : 'wx-authorized-collector';
$worker->count = 1;
$worker->onWorkerStart = static function () use ($runner, $interval): void {
    $running = false;
    $tick = static function () use ($runner, &$running): void {
        if ($running) {
            return;
        }
        $running = true;
        try {
            $runner->tick();
        } finally {
            $running = false;
        }
    };
    $tick();
    Timer::add($interval, $tick);
};

Worker::runAll();
