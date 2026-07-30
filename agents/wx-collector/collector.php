<?php

declare(strict_types=1);

use Workerman\Timer;
use Workerman\Worker;
use WxCollector\AlipayWebProviderAdapter;
use WxCollector\CloudClient;
use WxCollector\CollectorRunner;
use WxCollector\EncryptedFileStateStore;
use WxCollector\ProviderAdapterInterface;
use WxCollector\UnavailableProviderAdapter;
use WxCollector\WxPcHookProviderAdapter;

$autoload = is_file(__DIR__ . '/vendor/autoload.php')
    ? __DIR__ . '/vendor/autoload.php'
    : dirname(__DIR__, 2) . '/vendor/autoload.php';
require $autoload;

$collectorId  = trim((string)getenv('WXCOLLECTOR_ID'));
$secret       = (string)getenv('WXCOLLECTOR_SECRET');
$cloudUrl     = rtrim((string)getenv('WXCOLLECTOR_CLOUD_URL'), '/');
$interval     = max(1.0, min(30.0, (float)(getenv('WXCOLLECTOR_INTERVAL') ?: 2.0)));
$allowHttp    = filter_var(getenv('WXCOLLECTOR_ALLOW_HTTP') ?: false, FILTER_VALIDATE_BOOL);
$adapterClass = trim((string)(getenv('WXCOLLECTOR_ADAPTER_CLASS') ?: UnavailableProviderAdapter::class));

if (!class_exists($adapterClass)) {
    throw new RuntimeException("采集器适配器类不存在: {$adapterClass}");
}

// -----------------------------------------------------------------------
// 适配器初始化
// -----------------------------------------------------------------------
if ($adapterClass === AlipayWebProviderAdapter::class) {
    $adapter = new AlipayWebProviderAdapter(new EncryptedFileStateStore(
        (string)(getenv('ALIC_STATE_DIR') ?: __DIR__ . '/runtime/alipay-sessions'),
        (string)getenv('ALIC_MASTER_KEY')
    ));
} elseif ($adapterClass === WxPcHookProviderAdapter::class) {
    // 微信 PC Hook 适配器（依赖 aixed/WeChat-Hook，微信版本 4.1.10.27）
    $wxpcMasterKey = (string)getenv('WXPC_MASTER_KEY');
    if ($wxpcMasterKey === '') {
        throw new RuntimeException('微信 PC Hook 采集器需要设置 WXPC_MASTER_KEY 环境变量');
    }
    $adapter = new WxPcHookProviderAdapter(
        hookUrl:     (string)(getenv('WXPC_HOOK_URL') ?: 'http://127.0.0.1:30001'),
        storeDbPath: (string)(getenv('WXPC_BILL_DB') ?: __DIR__ . '/runtime/wx-pc-bills.sqlite'),
        masterKey:   $wxpcMasterKey,
        stateDir:    (string)(getenv('WXPC_STATE_DIR') ?: __DIR__ . '/runtime/wx-pc-sessions'),
    );
} else {
    $adapter = new $adapterClass();
}

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

// -----------------------------------------------------------------------
// Worker：单进程定时调度
// -----------------------------------------------------------------------
$workerName = match ($adapterClass) {
    AlipayWebProviderAdapter::class => 'alipay-web-collector',
    WxPcHookProviderAdapter::class  => 'wx-pc-hook-collector',
    default                         => 'wx-authorized-collector',
};

$worker = new Worker();
$worker->name  = $workerName;
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
