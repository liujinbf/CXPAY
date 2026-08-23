<?php
/**
 * CXPAY 管理员密码重置工具
 * 用法: php tools/reset_password.php [新密码] [管理员账号(可选)]
 */

$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    echo "❌ 未找到 .env 配置文件\n";
    exit(1);
}

// 加载 .env
$env = parse_ini_file($envFile);
$dbHost = $env['DB_HOST'] ?? '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? '3306';
$dbName = $env['DB_DATABASE'] ?? 'cxpay';
$dbUser = $env['DB_USERNAME'] ?? 'root';
$dbPass = $env['DB_PASSWORD'] ?? '';

try {
    $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Exception $e) {
    echo "❌ 数据库连接失败: " . $e->getMessage() . "\n";
    exit(1);
}

$newPass = $argv[1] ?? null;
$account = $argv[2] ?? null;

if (!$account) {
    $stmt = $pdo->query("SELECT value FROM cx_config WHERE name='admin_account'");
    $account = $stmt->fetchColumn() ?: 'admin';
}

if (!$newPass) {
    echo "请输入要设置的新管理员密码: ";
    $newPass = trim((string)fgets(STDIN));
}

if (strlen($newPass) < 6) {
    echo "❌ 密码长度至少为 6 位！\n";
    exit(1);
}

$hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
$salt = bin2hex(random_bytes(16));

// 1. 更新 cx_config
$stmt = $pdo->prepare("INSERT INTO cx_config (name, value, title) VALUES 
    ('admin_account', :acc, '管理员账号'),
    ('admin_password_hash', :pwd, '管理员密码 Bcrypt 哈希'),
    ('token_salt', :salt, 'Token HMAC 签名盐值')
    ON DUPLICATE KEY UPDATE value=VALUES(value)");
$stmt->execute([
    'acc'  => $account,
    'pwd'  => $hash,
    'salt' => $salt,
]);

// 2. 更新 cx_admin
try {
    $stmtAdmin = $pdo->prepare("INSERT INTO cx_admin (username, password_hash, role, display_name, status, create_time) 
        VALUES (:u, :p, 'root', '超级管理员', 1, UNIX_TIMESTAMP()) 
        ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role='root'");
    $stmtAdmin->execute([
        'u' => $account,
        'p' => $hash,
    ]);
} catch (Throwable $e) {}

echo "\n=======================================================\n";
echo "✓ 管理员密码重置成功！\n";
echo "  管理员账号: {$account}\n";
echo "  管理员密码: {$newPass}\n";
echo "  登录地址:   /admin_login.html\n";
echo "=======================================================\n";
