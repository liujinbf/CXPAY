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
if (!is_dir($runtimeDirectory) && !mkdir($runtimeDirectory, 0750, true) && !is_dir($runtimeDirectory)) {
    throw new RuntimeException('无法创建云监控运行目录');
}
$role = strtolower(trim((string)getenv('WXMC_PROVISION_ROLE')));
$id = trim((string)getenv('WXMC_PROVISION_ID'));
$requestSecret = (string)(getenv('WXMC_REQUEST_SECRET') ?: bin2hex(random_bytes(32)));
$responseSecret = $role === 'client'
    ? (string)(getenv('WXMC_RESPONSE_SECRET') ?: bin2hex(random_bytes(32)))
    : '';
$callbackUrl = $role === 'client' ? trim((string)getenv('WXMC_CALLBACK_URL')) : '';
if (!in_array($role, ['client', 'collector'], true)
    || !preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', $id)
    || strlen($requestSecret) < 32 || strlen($requestSecret) > 128
    || ($role === 'client' && (strlen($responseSecret) < 32 || strlen($responseSecret) > 128))) {
    fwrite(STDERR, "请设置合法的 WXMC_PROVISION_ROLE、WXMC_PROVISION_ID 和至少32位密钥\n");
    exit(2);
}
if ($role === 'client') {
    $parts = parse_url($callbackUrl);
    $callbackHost = is_array($parts) ? strtolower(rtrim((string)($parts['host'] ?? ''), '.')) : '';
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || $callbackHost === '' || $callbackHost === 'localhost'
        || (filter_var($callbackHost, FILTER_VALIDATE_IP)
            && filter_var(
                $callbackHost,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false)
        || isset($parts['user']) || isset($parts['pass'])) {
        fwrite(STDERR, "WXMC_CALLBACK_URL 必须是不含用户信息的 HTTPS 地址\n");
        exit(2);
    }
}

$databaseUser = getenv('WXMC_DB_USER');
$databasePassword = getenv('WXMC_DB_PASSWORD');
$pdo = Database::connect(
    (string)(getenv('WXMC_DSN') ?: 'sqlite:' . $runtimeDirectory . '/wx-monitor-cloud.sqlite'),
    $databaseUser === false || $databaseUser === '' ? null : (string)$databaseUser,
    $databasePassword === false ? null : (string)$databasePassword
);
$vault = new SecretVault((string)getenv('WXMC_MASTER_KEY'));
$encryptedRequestSecret = $vault->encrypt($requestSecret);
$encryptedResponseSecret = $responseSecret === '' ? '' : $vault->encrypt($responseSecret);
$upsertSql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
    ? 'INSERT INTO principals(id, role, request_secret, response_secret, callback_url, status, created_at)
       VALUES(?, ?, ?, ?, ?, 1, ?)
       ON DUPLICATE KEY UPDATE role = VALUES(role), request_secret = VALUES(request_secret),
         response_secret = VALUES(response_secret), callback_url = VALUES(callback_url), status = 1'
    : 'INSERT INTO principals(id, role, request_secret, response_secret, callback_url, status, created_at)
       VALUES(?, ?, ?, ?, ?, 1, ?)
       ON CONFLICT(id) DO UPDATE SET role = excluded.role, request_secret = excluded.request_secret,
         response_secret = excluded.response_secret, callback_url = excluded.callback_url, status = 1';
$statement = $pdo->prepare(
    $upsertSql
);
$pdo->beginTransaction();
try {
    $statement->execute([
        $id, $role, $encryptedRequestSecret, $encryptedResponseSecret,
        $callbackUrl, time(),
    ]);
    $pdo->prepare('DELETE FROM principal_keys WHERE principal_id = ?')->execute([$id]);
    $keys = new PrincipalKeyManager($pdo, $vault);
    $keys->register($id, 'request', $encryptedRequestSecret);
    if ($encryptedResponseSecret !== '') {
        $keys->register($id, 'response', $encryptedResponseSecret);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

fwrite(STDOUT, json_encode([
    'id' => $id,
    'role' => $role,
    'request_secret' => $requestSecret,
    'response_secret' => $responseSecret,
    'callback_url' => $callbackUrl,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
fwrite(STDERR, "密钥仅在本次命令输出，请立即保存到安全配置系统。\n");
