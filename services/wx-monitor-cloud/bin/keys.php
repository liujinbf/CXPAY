<?php

declare(strict_types=1);

use WxMonitorCloud\Database;
use WxMonitorCloud\PrincipalKeyManager;
use WxMonitorCloud\SecretVault;

$autoload = is_file(dirname(__DIR__) . '/vendor/autoload.php')
    ? dirname(__DIR__) . '/vendor/autoload.php'
    : dirname(__DIR__, 3) . '/vendor/autoload.php';
require $autoload;

$runtimeDirectory = dirname(__DIR__) . '/runtime';
$databaseUser = getenv('WXMC_DB_USER');
$databasePassword = getenv('WXMC_DB_PASSWORD');
$pdo = Database::connect(
    (string)(getenv('WXMC_DSN') ?: 'sqlite:' . $runtimeDirectory . '/wx-monitor-cloud.sqlite'),
    $databaseUser === false || $databaseUser === '' ? null : (string)$databaseUser,
    $databasePassword === false ? null : (string)$databasePassword
);
$manager = new PrincipalKeyManager($pdo, new SecretVault((string)getenv('WXMC_MASTER_KEY')));
$action = strtolower(trim((string)getenv('WXMC_KEY_ACTION')));
$principalId = trim((string)getenv('WXMC_PROVISION_ID'));
if (!preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', $principalId)
    || !in_array($action, ['list', 'rotate', 'revoke'], true)) {
    fwrite(STDERR, "请设置 WXMC_KEY_ACTION=list|rotate|revoke 和合法的 WXMC_PROVISION_ID\n");
    exit(2);
}

if ($action === 'list') {
    $result = ['principal_id' => $principalId, 'keys' => $manager->list($principalId)];
} elseif ($action === 'rotate') {
    $type = strtolower(trim((string)getenv('WXMC_KEY_TYPE')));
    $graceValue = getenv('WXMC_KEY_GRACE_SECONDS');
    $activateValue = getenv('WXMC_KEY_ACTIVATE_AFTER_SECONDS');
    $grace = $graceValue === false || $graceValue === '' ? 3600 : (int)$graceValue;
    $activateAfter = $activateValue === false || $activateValue === '' ? 0 : (int)$activateValue;
    $provided = getenv('WXMC_NEW_SECRET');
    $result = $manager->rotate(
        $principalId,
        $type,
        $grace,
        $provided === false || $provided === '' ? null : (string)$provided,
        $activateAfter
    );
    $result['principal_id'] = $principalId;
    fwrite(STDERR, "新密钥仅在本次命令输出，请更新调用端安全配置。\n");
} else {
    $keyId = trim((string)getenv('WXMC_KEY_ID'));
    if (!preg_match('/^key_[a-f0-9]{24}$/', $keyId)) {
        fwrite(STDERR, "WXMC_KEY_ID 格式不合法\n");
        exit(2);
    }
    $result = ['principal_id' => $principalId, 'key_id' => $keyId, 'revoked' => $manager->revoke($principalId, $keyId)];
}

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
