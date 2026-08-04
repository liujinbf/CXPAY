#!/usr/bin/env php
<?php

declare(strict_types=1);

use app\service\LegacyPaymentDriverCleanupService;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

$baseDir = dirname(__DIR__, 2);

require $baseDir . '/vendor/autoload.php';

if (is_file($baseDir . '/.env')) {
    Dotenv::createUnsafeMutable($baseDir)->safeLoad();
}

$args = array_slice($argv, 1);

if ($args === []) {
    $apply = false;
} elseif ($args === ['--apply']) {
    $apply = true;
} else {
    fwrite(
        STDERR,
        "Usage:\n"
        . "  php database/migrations/"
        . "20260805_remove_legacy_payment_drivers.php\n"
        . "  php database/migrations/"
        . "20260805_remove_legacy_payment_drivers.php --apply\n"
    );
    exit(64);
}

try {
    $config = require $baseDir . '/config/database.php';

    if (!isset($config['connections']['mysql'])
        || !is_array($config['connections']['mysql'])
    ) {
        throw new RuntimeException('MySQL 数据库连接配置不存在');
    }

    $capsule = new Capsule();
    $capsule->addConnection(
        $config['connections']['mysql'],
        'mysql'
    );
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $service = new LegacyPaymentDriverCleanupService(
        $capsule->getConnection('mysql')
    );

    // MySQL DDL 会隐式提交，因此建表与事务性 DML 明确分离。
    $service->ensureArchiveTable();

    $preview = $service->preview();

    echo $apply ? "MODE=APPLY\n" : "MODE=DRY-RUN\n";
    echo "channel_count={$preview['channel_count']}\n";
    echo "poll_group_links={$preview['poll_group_links']}\n";
    echo "plans_to_update={$preview['plans_to_update']}\n";
    echo "pending_orders={$preview['pending_orders']}\n";
    echo "channels:\n";

    foreach ($preview['channels'] as $channel) {
        printf(
            "  id=%d merchant_id=%d c_type=%s title=%s\n",
            (int)($channel['id'] ?? 0),
            (int)($channel['merchant_id'] ?? 0),
            (string)($channel['c_type'] ?? ''),
            str_replace(
                ["\r", "\n"],
                ' ',
                (string)($channel['title'] ?? '')
            )
        );
    }

    if (!$apply) {
        echo "DRY-RUN: no active channel data changed\n";
        exit(0);
    }

    if ((int)$preview['pending_orders'] > 0) {
        fwrite(
            STDERR,
            "Cleanup blocked: pending orders still reference "
            . "removed channels\n"
        );
        exit(2);
    }

    $result = $service->apply();

    echo "archived={$result['archived']}\n";
    echo "poll_group_links_deleted="
        . "{$result['poll_group_links_deleted']}\n";
    echo "plans_updated={$result['plans_updated']}\n";
    echo "channels_deleted={$result['channels_deleted']}\n";
    echo "remaining={$result['remaining']}\n";
    echo "APPLY completed successfully\n";
} catch (Throwable $e) {
    fwrite(
        STDERR,
        'Migration failed: ' . $e->getMessage() . PHP_EOL
    );
    exit(1);
}
