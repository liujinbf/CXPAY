<?php

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo ".env 文件不存在！\n";
    exit(1);
}

$env = parse_ini_file($envFile);
$dbHost = $env['DB_HOST'] ?? '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? '3306';
$dbName = $env['DB_DATABASE'] ?? 'cxpay';
$dbUser = $env['DB_USERNAME'] ?? 'root';
$dbPass = $env['DB_PASSWORD'] ?? '';

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $newPass = $argv[1] ?? 'aa112233';
    $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $pdo->prepare("UPDATE cx_config SET value = :hash WHERE name = 'admin_password_hash'");
    $stmt->execute([':hash' => $hash]);

    $stmt = $pdo->prepare("SELECT value FROM cx_config WHERE name = 'admin_account'");
    $stmt->execute();
    $account = $stmt->fetchColumn() ?: 'admin';

    echo "========================================\n";
    echo "  ✅ 密码重置成功！\n";
    echo "  👤 管理员账号：[ {$account} ]\n";
    echo "  🔑 重置后密码：[ {$newPass} ]\n";
    echo "========================================\n";

} catch (Exception $e) {
    echo "❌ 数据库连接或重置失败：" . $e->getMessage() . "\n";
}
