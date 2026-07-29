<?php

declare(strict_types=1);

namespace app\controller\api;

use support\Response;
use Throwable;
use PDO;

/**
 * 安装向导后端 API 控制器
 * 增强：
 *   - 环境与可写权限检测（.env、runtime、install.lock）
 *   - 严格拦截 PHP 警告/错误输出，确保 API 永远返回标准 JSON
 *   - 自动写入 .env
 *   - 管理员初始密码采用安全 bcrypt 哈希存入 cx_config
 */
class InstallController
{
    /**
     * 1. 实时服务器环境与可写权限检测 API
     */
    public function check(\support\Request $request)
    {
        $baseDir    = function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
        $runtimeDir = rtrim($baseDir, '/\\') . '/runtime';
        $envFile    = rtrim($baseDir, '/\\') . '/.env';
        $lockFile = (string)config('app.install_lock', rtrim($baseDir, '/\\') . '/install.lock');

        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0777, true);
        }

        $phpVersion = PHP_VERSION;
        $phpOk      = version_compare($phpVersion, '8.1.0', '>=');

        $exts = [
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'redis'     => extension_loaded('redis'),
            'bcmath'    => extension_loaded('bcmath'),
            'openssl'   => extension_loaded('openssl'),
            'curl'      => extension_loaded('curl'),
            'json'      => extension_loaded('json'),
        ];

        $allExtsOk = $exts['pdo_mysql'] && $exts['redis'] && $exts['bcmath'] && $exts['openssl'] && $exts['curl'];

        // 测试各文件/目录的实际写入能力
        $perms = [
            'runtime_writable'   => is_writable($runtimeDir),
            'root_writable'      => is_writable($baseDir),
            'env_writable'       => !file_exists($envFile) ? is_writable($baseDir) : is_writable($envFile),
        ];

        // 只要 runtime 目录和 env 配置可写，即可支持完美一键安装落盘
        $allPermsOk = !in_array(false, $perms, true);
        $perms['all_perms_ok'] = $allPermsOk;
        $installed  = file_exists($lockFile);

        return json([
            'code' => 1,
            'data' => [
                'php_version'  => $phpVersion,
                'php_ok'       => $phpOk,
                'os'           => PHP_OS,
                'extensions'   => $exts,
                'all_exts_ok'  => $allExtsOk,
                'permissions'  => $perms,
                'all_perms_ok' => $allPermsOk,
                'installed'    => $installed,
                'can_install'  => $phpOk && $allExtsOk && !$installed,
            ]
        ]);
    }

    /**
     * 2. 数据库连接实时测试与数据库预创建 API
     */
    public function testDb(\support\Request $request)
    {
        if (file_exists((string)config('app.install_lock', base_path() . '/install.lock'))) {
            return json(['code' => -1, 'msg' => '系统已安装，数据库测试接口已关闭'])->withStatus(403);
        }
        // 开启输出缓冲区防护，屏蔽底层任何可能的 HTML/Warning 污染
        if (ob_get_level() === 0) {
            ob_start();
        } else {
            @ob_clean();
        }

        // 全渠道参数兼容解析 (support/Request, $_POST, php://input)
        $params = [];
        if (is_object($request) && method_exists($request, 'post')) {
            $params = $request->post() ?: [];
        }
        if (empty($params)) {
            $params = $_POST ?: $_GET ?: $_REQUEST;
        }
        if (empty($params)) {
            $rawInput = @file_get_contents('php://input');
            if (!empty($rawInput)) {
                parse_str($rawInput, $parsed);
                if (!empty($parsed)) {
                    $params = $parsed;
                } else {
                    $params = json_decode($rawInput, true) ?: [];
                }
            }
        }

        $dbHost = trim((string)($params['db_host'] ?? '127.0.0.1'));
        $dbPort = trim((string)($params['db_port'] ?? '3306'));
        $dbName = trim((string)($params['db_name'] ?? 'cxpay'));
        $dbUser = trim((string)($params['db_user'] ?? 'root'));
        $dbPass = trim((string)($params['db_pass'] ?? ''));

        if (empty($dbHost) || empty($dbName) || empty($dbUser)) {
            @ob_clean();
            return json(['code' => -1, 'msg' => '数据库主机、名称与用户名不能为空']);
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,255}$/', $dbHost)
            || !preg_match('/^[A-Za-z0-9_]+$/', $dbName)
            || strlen($dbUser) > 128
            || !ctype_digit($dbPort)
            || (int)$dbPort < 1
            || (int)$dbPort > 65535) {
            @ob_clean();
            return json(['code' => -1, 'msg' => '数据库名称或端口格式不合法']);
        }

        try {
            // 先不指定数据库名连接 MySQL 实例
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ];
            if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
            }
            $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
            @$pdo->exec("SET NAMES utf8mb4");

            // 获取 MySQL 版本
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

            // 尝试自动创建数据库（若不存在）
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");

            @ob_clean();
            return json([
                'code'    => 1,
                'msg'     => "✅ 数据库连接校验成功！MySQL 版本: {$version}，已准备好建表导入。",
                'version' => $version
            ]);
        } catch (Throwable $e) {
            @ob_clean();
            $msg = $e->getMessage();
            if (str_contains($msg, 'Access denied')) {
                $msg = "数据库账号或密码错误 (Access denied for user '{$dbUser}')";
            } elseif (str_contains($msg, 'Connection refused') || str_contains($msg, "Can't connect")) {
                $msg = "无法连接至数据库主机 ({$dbHost}:{$dbPort})，请检查数据库服务状态与防火墙规则";
            }
            return json(['code' => -1, 'msg' => "❌ 数据库连接失败: {$msg}"]);
        }
    }

    /**
     * 3. 自动构建数据库表结构、落盘配置与生成锁文件 API
     */
    public function execute(\support\Request $request)
    {
        if (ob_get_level() === 0) {
            ob_start();
        } else {
            @ob_clean();
        }

        $baseDir  = function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
        $lockFile = (string)config('app.install_lock', rtrim($baseDir, '/\\') . '/install.lock');

        if (file_exists($lockFile)) {
            @ob_clean();
            return json(['code' => -1, 'msg' => '⚠️ 系统已被安装过！如需重新安装，请删除根目录下的 install.lock 文件']);
        }

        // 全渠道参数兼容解析
        $params = [];
        if (is_object($request) && method_exists($request, 'post')) {
            $params = $request->post() ?: [];
        }
        if (empty($params)) {
            $params = $_POST ?: $_GET ?: $_REQUEST;
        }
        if (empty($params)) {
            $rawInput = @file_get_contents('php://input');
            if (!empty($rawInput)) {
                parse_str($rawInput, $parsed);
                if (!empty($parsed)) {
                    $params = $parsed;
                } else {
                    $params = json_decode($rawInput, true) ?: [];
                }
            }
        }
        $dbHost = trim((string)($params['db_host'] ?? '127.0.0.1'));
        $dbPort = trim((string)($params['db_port'] ?? '3306'));
        $dbName = trim((string)($params['db_name'] ?? 'cxpay'));
        $dbUser = trim((string)($params['db_user'] ?? 'root'));
        $dbPass = trim((string)($params['db_pass'] ?? ''));
        $adminUser = trim((string)($params['admin_user'] ?? 'admin'));
        $adminPass = trim((string)($params['admin_pass'] ?? '123456'));

        if (empty($adminUser) || empty($adminPass)) {
            return json(['code' => -1, 'msg' => '超级管理员用户名与密码不能为空']);
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,255}$/', $dbHost)
            || !preg_match('/^[A-Za-z0-9_]+$/', $dbName)
            || strlen($dbUser) > 128
            || !ctype_digit($dbPort)
            || (int)$dbPort < 1
            || (int)$dbPort > 65535) {
            return json(['code' => -1, 'msg' => '数据库名称或端口格式不合法']);
        }
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $adminUser)) {
            return json(['code' => -1, 'msg' => '管理员用户名仅允许3至64位字母、数字及 _ . @ -']);
        }
        if (strlen($adminPass) < 10 || strlen($adminPass) > 200) {
            return json(['code' => -1, 'msg' => '管理员密码长度必须为10至200个字符']);
        }

        try {
            // 1. PDO 建立连接并创建目标数据库
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ];
            if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";
            }
            $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
            @$pdo->exec("SET NAMES utf8mb4");
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            $pdo->exec("USE `{$dbName}`;");

            // 2. 读取并导入 install.sql。先移除行注释，避免“注释 + SQL”整段被误跳过。
            $sqlFile = rtrim($baseDir, '/\\') . '/database/install.sql';
            if (file_exists($sqlFile)) {
                $sqlContent = file_get_contents($sqlFile);
                $sqlContent = preg_replace('/^\s*--.*$/m', '', $sqlContent) ?? $sqlContent;
                $statements = array_filter(array_map('trim', explode(';', $sqlContent)));
                foreach ($statements as $stmt) {
                    if (!empty($stmt)) {
                        $pdo->exec($stmt);
                    }
                }
            } else {
                throw new \RuntimeException('数据库初始化脚本 database/install.sql 不存在');
            }

            // 安装成功前必须验证核心数据表真实存在。
            foreach (['cx_merchant', 'cx_order', 'cx_pay_channel', 'cx_config'] as $requiredTable) {
                $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($requiredTable))->fetchColumn();
                if (!$exists) {
                    throw new \RuntimeException("核心数据表 {$requiredTable} 创建失败");
                }
            }

            // 3. 更新管理员账号和密码（Bcrypt 哈希）
            $passwordHash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $tokenSalt    = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("
                INSERT INTO `cx_config` (`name`, `value`, `title`) VALUES
                ('admin_account', :u, '管理员账号'),
                ('admin_password_hash', :p, '管理员密码Bcrypt哈希'),
                ('token_salt', :s, 'Token HMAC签名盐值')
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
            ");
            $stmt->execute([':u' => $adminUser, ':p' => $passwordHash, ':s' => $tokenSalt]);

            // 4. 只落盘环境变量；数据库配置文件保持版本库中的统一结构。

            // 写入 .env 文件
            $envFile = rtrim($baseDir, '/\\') . '/.env';
            $envValue = static fn(string $value): string => '"' . addcslashes($value, "\\\"\r\n") . '"';
            $forwardedProto = strtolower((string)$request->header('x-forwarded-proto'));
            $scheme = in_array($forwardedProto, ['http', 'https'], true) ? $forwardedProto : 'http';
            $appUrl = $scheme . '://' . $request->host();
            $appKey = bin2hex(random_bytes(32));
            $envCode = 'APP_DEBUG=false' . "\n"
                . 'APP_URL=' . $envValue($appUrl) . "\n"
                . 'APP_KEY=' . $envValue($appKey) . "\n"
                . 'ALLOW_PRIVATE_CALLBACKS=false' . "\n"
                . 'SYSTEM_UPDATE_ENABLED=false' . "\n"
                . 'DB_HOST=' . $envValue($dbHost) . "\n"
                . 'DB_PORT=' . $envValue($dbPort) . "\n"
                . 'DB_DATABASE=' . $envValue($dbName) . "\n"
                . 'DB_USERNAME=' . $envValue($dbUser) . "\n"
                . 'DB_PASSWORD=' . $envValue($dbPass) . "\n"
                . 'REDIS_HOST=' . $envValue((string)env('REDIS_HOST', '127.0.0.1')) . "\n"
                . 'REDIS_PORT=' . $envValue((string)env('REDIS_PORT', '6379')) . "\n"
                . 'REDIS_PASSWORD=' . $envValue((string)env('REDIS_PASSWORD', '')) . "\n"
                . 'REDIS_DB=' . $envValue((string)env('REDIS_DB', '0')) . "\n";
            if (file_put_contents($envFile, $envCode, LOCK_EX) === false) {
                throw new \RuntimeException('无法写入 .env 环境配置文件');
            }

            // 5. 生成 install.lock 安装锁文件与安全隔离标记
            $lockDirectory = dirname($lockFile);
            if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0750, true) && !is_dir($lockDirectory)) {
                throw new \RuntimeException('无法创建安装锁目录');
            }
            if (file_put_contents($lockFile, date('Y-m-d H:i:s') . " Installed Successfully by CXPAY Auto Installer.\nSTATUS=INSTALLED_AND_LOCKED\n", LOCK_EX) === false) {
                throw new \RuntimeException('无法写入安装锁文件');
            }

            // 6. 刷新配置缓存
            try {
                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                }
            } catch (Throwable) {}

            @ob_clean();
            return json([
                'code' => 1,
                'msg'  => '🎉 CXPAY 商业级聚合支付系统初始化成功！安装锁 (install.lock) 与数据库配置已自动落盘安全锁定！'
            ]);
        } catch (Throwable $e) {
            @ob_clean();
            return json(['code' => -1, 'msg' => '安装失败: ' . $e->getMessage()]);
        }
    }
}
