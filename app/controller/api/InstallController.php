<?php

declare(strict_types=1);

namespace app\controller\api;

use PDO;
use RuntimeException;
use Throwable;

/**
 * 面向新手的安全安装向导。
 *
 * 安装器只服务于全新、专用的空数据库：它负责检测环境、创建数据库、导入表结构、
 * 生成随机密钥并写入安装锁；不会尝试覆盖已有站点或已有数据。
 *
 * v2 新增能力：
 *  - testRedis()：通过 Socket 直连测试 Redis 服务可用性与密码正确性
 *  - environmentInfo()：检测宿主环境类型（systemd / 宝塔 / Docker / 手动）
 *  - execute() 现接受前端传入的 Redis 配置并写入 .env
 */
class InstallController
{
    private const REQUIRED_EXTENSIONS = ['pdo_mysql', 'redis', 'bcmath', 'mbstring', 'openssl', 'curl'];

    // -------------------------------------------------------------------------
    // 公开接口
    // -------------------------------------------------------------------------

    public function check(\support\Request $request)
    {
        $baseDir = $this->baseDir();
        $runtimeDir = $baseDir . '/runtime';
        $logDir = $runtimeDir . '/logs';
        $envFile = $baseDir . '/.env';
        $lockFile = $this->lockFile($baseDir);

        foreach ([$runtimeDir, $logDir] as $directory) {
            if (!is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
        }

        $extensions = [];
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }
        $extensions['json'] = extension_loaded('json');
        $extensions['pcntl'] = DIRECTORY_SEPARATOR !== '/' || extension_loaded('pcntl');

        $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
        $extensionsOk = !in_array(false, array_intersect_key($extensions, array_flip(self::REQUIRED_EXTENSIONS)), true)
            && $extensions['pcntl'];
        $permissions = [
            'runtime_writable' => is_writable($runtimeDir),
            'logs_writable'    => is_writable($logDir),
            'env_writable'     => file_exists($envFile) ? is_writable($envFile) : is_writable($baseDir),
            'lock_writable'    => is_writable(dirname($lockFile)),
        ];
        $permissions['all_perms_ok'] = !in_array(false, $permissions, true);
        $installed = file_exists($lockFile);
        $autoloadReady = file_exists($baseDir . '/vendor/autoload.php');
        $webmanProcess = PHP_SAPI === 'cli';

        return json([
            'code' => 1,
            'data' => [
                'php_version'    => PHP_VERSION,
                'php_ok'         => $phpOk,
                'os'             => PHP_OS,
                'extensions'     => $extensions,
                'all_exts_ok'    => $extensionsOk,
                'permissions'    => $permissions,
                'all_perms_ok'   => $permissions['all_perms_ok'],
                'autoload_ready' => $autoloadReady,
                'webman_process' => $webmanProcess,
                'installed'      => $installed,
                'can_install'    => $phpOk && $extensionsOk && $permissions['all_perms_ok'] && $autoloadReady && $webmanProcess && !$installed,
                'fix_hints'      => $this->fixHints($extensions, $permissions, $autoloadReady, $webmanProcess),
            ],
        ]);
    }

    /**
     * 通过原生 Socket 测试 Redis 连通性与密码正确性。
     * 不依赖 PHP redis 扩展（扩展已在 check 中验证）。
     */
    public function testRedis(\support\Request $request)
    {
        if ($this->isInstalled()) {
            return json(['code' => -1, 'msg' => '系统已安装，Redis 测试接口已关闭'])->withStatus(403);
        }

        $params = $this->requestParams($request);
        $host   = trim((string)($params['redis_host']     ?? '127.0.0.1'));
        $port   = (int)($params['redis_port']             ?? 6379);
        $pass   = (string)($params['redis_password']      ?? '');
        $db     = (int)($params['redis_db']               ?? 0);

        if ($host === '') {
            return json(['code' => -1, 'msg' => 'Redis 主机不能为空']);
        }
        if ($port < 1 || $port > 65535) {
            return json(['code' => -1, 'msg' => 'Redis 端口范围 1–65535']);
        }

        $errno  = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 3.0);
        if ($socket === false) {
            return json([
                'code' => -1,
                'msg'  => sprintf('无法连接 Redis %s:%d — %s（错误码 %d）。请检查主机地址、端口与防火墙。', $host, $port, $errstr, $errno),
            ]);
        }

        try {
            stream_set_timeout($socket, 3);

            // 若设置了密码，先发 AUTH
            if ($pass !== '') {
                fwrite($socket, sprintf("*2\r\n\$4\r\nAUTH\r\n\$%d\r\n%s\r\n", strlen($pass), $pass));
                $authReply = fgets($socket, 256);
                if ($authReply === false || !str_starts_with(trim($authReply), '+OK')) {
                    $hint = str_contains((string)$authReply, 'WRONGPASS') ? '密码错误，请确认 Redis requirepass 配置。' : '密码验证失败，服务器回应：' . trim((string)$authReply);
                    return json(['code' => -1, 'msg' => $hint]);
                }
            }

            // SELECT db
            if ($db !== 0) {
                fwrite($socket, sprintf("*2\r\n\$6\r\nSELECT\r\n\$%d\r\n%d\r\n", strlen((string)$db), $db));
                $selectReply = fgets($socket, 256);
                if ($selectReply === false || !str_starts_with(trim($selectReply), '+OK')) {
                    return json(['code' => -1, 'msg' => 'Redis DB 切换失败：' . trim((string)$selectReply)]);
                }
            }

            // PING
            fwrite($socket, "*1\r\n\$4\r\nPING\r\n");
            $pong = fgets($socket, 256);
            if ($pong === false || !str_starts_with(trim($pong), '+PONG')) {
                return json(['code' => -1, 'msg' => 'Redis PING 未收到 PONG，服务器回应：' . trim((string)$pong)]);
            }

            // INFO server — 取版本
            fwrite($socket, "*2\r\n\$4\r\nINFO\r\n\$6\r\nserver\r\n");
            $infoLen = fgets($socket, 64);   // $NNNN
            $version = '未知';
            if ($infoLen !== false && str_starts_with($infoLen, '$')) {
                $length = (int)ltrim($infoLen, '$');
                $info   = '';
                while (strlen($info) < $length + 2) {
                    $chunk = fread($socket, $length + 2 - strlen($info));
                    if ($chunk === false) {
                        break;
                    }
                    $info .= $chunk;
                }
                if (preg_match('/redis_version:(\S+)/', $info, $m)) {
                    $version = $m[1];
                }
            }

            return json([
                'code' => 1,
                'msg'  => sprintf('Redis %s 连接成功（%s:%d / DB%d）%s。', $version, $host, $port, $db, $pass !== '' ? '，密码验证通过' : '，无需密码'),
                'data' => ['version' => $version],
            ]);
        } finally {
            fclose($socket);
        }
    }

    /**
     * 检测宿主环境类型，辅助前端在安装完成页显示正确的重启命令。
     */
    public function environmentInfo(\support\Request $request)
    {
        if ($this->isInstalled()) {
            return json(['code' => -1, 'msg' => '系统已安装'])->withStatus(403);
        }

        $type   = 'manual';       // manual | systemd | pagoda | docker
        $detail = [];

        // Docker 环境
        if (file_exists('/.dockerenv') || getenv('container') === 'docker') {
            $type           = 'docker';
            $detail['note'] = '检测到 Docker 环境';
        }
        // 宝塔面板
        elseif (is_dir('/www/server/panel') || is_dir('/bt/panel')) {
            $type           = 'pagoda';
            $detail['note'] = '检测到宝塔面板';
        }
        // systemd + 自定义服务
        elseif (is_file('/etc/systemd/system/cxpay-webman.service') || is_file('/lib/systemd/system/cxpay-webman.service')) {
            $type           = 'systemd';
            $detail['note'] = '检测到 systemd cxpay-webman 服务';
        }
        // 通用 systemd（没有预置服务文件，但系统支持 systemd）
        elseif (is_dir('/etc/systemd')) {
            $type           = 'systemd_generic';
            $detail['note'] = '检测到 systemd，但尚未为 CXPAY 创建 service 文件';
        }

        // 检测 Supervisor
        $hasSupervisor = is_file('/usr/bin/supervisorctl') || is_file('/usr/local/bin/supervisorctl');

        return json([
            'code' => 1,
            'data' => [
                'env_type'      => $type,
                'has_supervisor'=> $hasSupervisor,
                'os'            => PHP_OS,
                'detail'        => $detail,
            ],
        ]);
    }

    /**
     * 仅验证数据库并反馈是否为空；不会导入任何业务数据。
     */
    public function testDb(\support\Request $request)
    {
        if ($this->isInstalled()) {
            return json(['code' => -1, 'msg' => '系统已安装，数据库测试接口已关闭'])->withStatus(403);
        }

        try {
            $params  = $this->databaseParams($this->requestParams($request));
            $pdo     = $this->connectDatabase($params, false);
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $params['name']
            ));
            $summary = $this->databaseSummary($pdo, $params['name']);

            if (!$summary['is_empty']) {
                return json([
                    'code' => -1,
                    'msg'  => '数据库连接成功，但目标库不是空库。为防止覆盖数据，安装器已停止；请创建一个全新的专用数据库。',
                    'data' => $summary,
                ]);
            }

            return json([
                'code' => 1,
                'msg'  => sprintf('数据库连接成功（MySQL %s），目标数据库为空，可以安全安装。', $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)),
                'data' => $summary,
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $this->databaseErrorMessage($e)]);
        }
    }

    /**
     * 导入全新数据库、写入配置与安装锁。已有库一律拒绝执行。
     * v2：现接受 redis_host/redis_port/redis_password/redis_db 参数并写入 .env。
     */
    public function execute(\support\Request $request)
    {
        if ($this->isInstalled()) {
            return json(['code' => -1, 'msg' => '系统已安装。若需要迁移旧站，请使用数据库补丁，不要删除安装锁。']);
        }

        $preflight = $this->preflight();
        if (!$preflight['ok']) {
            return json(['code' => -1, 'msg' => '服务器环境尚未准备完成：' . implode('；', $preflight['errors'])]);
        }

        $guardFile = $this->lockFile($this->baseDir()) . '.installing';
        $guard     = @fopen($guardFile, 'x');
        if ($guard === false) {
            return json(['code' => -1, 'msg' => '已有安装任务正在执行，请不要重复提交；如任务已异常中断，请联系管理员检查安装状态。']);
        }

        try {
            $params    = $this->requestParams($request);
            $database  = $this->databaseParams($params);
            $redis     = $this->redisParams($params);
            $adminUser = trim((string)($params['admin_user'] ?? 'admin'));
            $adminPass = (string)($params['admin_pass'] ?? '');
            $appUrl    = $this->applicationUrl((string)($params['app_url'] ?? ''));

            if (!preg_match('/^[A-Za-z0-9_.@-]{3,64}$/', $adminUser)) {
                throw new RuntimeException('管理员用户名仅允许 3 至 64 位字母、数字及 _ . @ -');
            }
            if (!$this->isStrongPassword($adminPass)) {
                throw new RuntimeException('管理员密码至少 12 位，且应同时包含大写字母、小写字母、数字和符号中的至少三类');
            }

            $pdo = $this->connectDatabase($database, false);
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $database['name']
            ));
            $summary = $this->databaseSummary($pdo, $database['name']);
            if (!$summary['is_empty']) {
                throw new RuntimeException('目标数据库不是空库，安装器拒绝覆盖已有数据。请改用新的专用数据库。');
            }

            $pdo->exec(sprintf('USE `%s`', $database['name']));
            $this->importSchema($pdo);
            $this->assertCoreTables($pdo);

            $statement = $pdo->prepare(
                'INSERT INTO `cx_config` (`name`, `value`, `title`) VALUES
                    (\'admin_account\', :account, \'管理员账号\'),
                    (\'admin_password_hash\', :password, \'管理员密码 Bcrypt 哈希\'),
                    (\'token_salt\', :salt, \'Token HMAC 签名盐值\')
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
            );
            $statement->execute([
                ':account'  => $adminUser,
                ':password' => password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]),
                ':salt'     => bin2hex(random_bytes(16)),
            ]);

            $this->writeEnvironment($database, $redis, $appUrl);
            $this->writeInstallLock();
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            return json([
                'code' => 1,
                'msg'  => '安装成功。数据库、随机密钥和安装锁已写入。请按页面指引重启 CXPAY 服务后登录后台。',
                'data' => [
                    'admin_url'       => $appUrl . '/admin_login.html',
                    'service_command' => 'systemctl restart cxpay-webman',
                ],
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $this->installErrorMessage($e)]);
        } finally {
            fclose($guard);
            @unlink($guardFile);
        }
    }

    // -------------------------------------------------------------------------
    // 私有辅助方法
    // -------------------------------------------------------------------------

    private function preflight(): array
    {
        $baseDir = $this->baseDir();
        $errors  = [];
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $errors[] = 'PHP 版本必须为 8.1 或更高';
        }
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (!extension_loaded($extension)) {
                $errors[] = '缺少 PHP 扩展：' . $extension;
            }
        }
        if (DIRECTORY_SEPARATOR === '/' && !extension_loaded('pcntl')) {
            $errors[] = 'Linux 环境缺少 PHP 扩展：pcntl';
        }
        if (!file_exists($baseDir . '/vendor/autoload.php')) {
            $errors[] = '依赖尚未安装，请在项目目录执行 composer install --no-dev --optimize-autoloader';
        }
        if (PHP_SAPI !== 'cli') {
            $errors[] = '当前不是 Webman 常驻进程。请启动 Webman 并配置 Nginx 反向代理后再访问安装器';
        }
        foreach ([$baseDir . '/runtime', $baseDir . '/runtime/logs'] as $directory) {
            if (!is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }
            if (!is_writable($directory)) {
                $errors[] = '目录不可写：' . basename($directory);
            }
        }
        $envFile = $baseDir . '/.env';
        if (file_exists($envFile) ? !is_writable($envFile) : !is_writable($baseDir)) {
            $errors[] = '.env 文件或项目根目录不可写';
        }
        return ['ok' => $errors === [], 'errors' => $errors];
    }

    private function databaseParams(array $params): array
    {
        $database = [
            'host'     => trim((string)($params['db_host'] ?? '127.0.0.1')),
            'port'     => trim((string)($params['db_port'] ?? '3306')),
            'name'     => trim((string)($params['db_name'] ?? 'cxpay')),
            'user'     => trim((string)($params['db_user'] ?? 'root')),
            'password' => (string)($params['db_pass'] ?? ''),
        ];
        if ($database['host'] === '' || $database['name'] === '' || $database['user'] === '') {
            throw new RuntimeException('数据库主机、名称与用户名不能为空');
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,255}$/', $database['host'])
            || !preg_match('/^[A-Za-z0-9_]+$/', $database['name'])
            || strlen($database['user']) > 128
            || !ctype_digit($database['port'])
            || (int)$database['port'] < 1
            || (int)$database['port'] > 65535) {
            throw new RuntimeException('数据库主机、名称或端口格式不合法');
        }
        return $database;
    }

    private function redisParams(array $params): array
    {
        $host = trim((string)($params['redis_host'] ?? '127.0.0.1'));
        $port = (int)($params['redis_port']         ?? 6379);
        $pass = (string)($params['redis_password']  ?? '');
        $db   = (int)($params['redis_db']           ?? 0);

        if ($host === '') {
            $host = '127.0.0.1';
        }
        if ($port < 1 || $port > 65535) {
            $port = 6379;
        }
        return ['host' => $host, 'port' => $port, 'password' => $pass, 'db' => $db];
    }

    private function connectDatabase(array $database, bool $selectDatabase): PDO
    {
        $databaseName = $selectDatabase ? ';dbname=' . $database['name'] : '';
        return new PDO(
            sprintf('mysql:host=%s;port=%s%s;charset=utf8mb4', $database['host'], $database['port'], $databaseName),
            $database['user'],
            $database['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
    }

    private function databaseSummary(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema');
        $statement->execute([':schema' => $database]);
        $tableCount = (int)$statement->fetchColumn();
        return ['table_count' => $tableCount, 'is_empty' => $tableCount === 0];
    }

    private function importSchema(PDO $pdo): void
    {
        $sqlFile = $this->baseDir() . '/database/install.sql';
        if (!is_file($sqlFile)) {
            throw new RuntimeException('缺少数据库初始化文件 database/install.sql');
        }
        $content = file_get_contents($sqlFile);
        if ($content === false) {
            throw new RuntimeException('无法读取数据库初始化文件');
        }
        $content = preg_replace('/^\s*--.*$/m', '', $content) ?? $content;
        foreach (array_filter(array_map('trim', explode(';', $content))) as $statement) {
            $pdo->exec($statement);
        }
    }

    private function assertCoreTables(PDO $pdo): void
    {
        foreach (['cx_merchant', 'cx_order', 'cx_pay_channel', 'cx_config', 'cx_bill_source_event'] as $table) {
            if (!$pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn()) {
                throw new RuntimeException('核心数据表创建失败：' . $table);
            }
        }
    }

    private function writeEnvironment(array $database, array $redis, string $appUrl): void
    {
        $quote   = static fn(string $value): string => '"' . addcslashes($value, "\\\"\r\n") . '"';
        $content = 'APP_DEBUG=false' . "\n"
            . 'APP_URL=' . $quote($appUrl) . "\n"
            . 'APP_KEY=' . $quote(bin2hex(random_bytes(32))) . "\n"
            . 'ALLOW_PRIVATE_CALLBACKS=false' . "\n"
            . 'SYSTEM_UPDATE_ENABLED=false' . "\n"
            . 'HOST=127.0.0.1' . "\n"
            . 'PORT=8787' . "\n"
            . 'WEBMAN_WORKERS=4' . "\n\n"
            . 'DB_HOST=' . $quote($database['host']) . "\n"
            . 'DB_PORT=' . $quote($database['port']) . "\n"
            . 'DB_DATABASE=' . $quote($database['name']) . "\n"
            . 'DB_USERNAME=' . $quote($database['user']) . "\n"
            . 'DB_PASSWORD=' . $quote($database['password']) . "\n\n"
            . 'REDIS_HOST=' . $quote($redis['host']) . "\n"
            . 'REDIS_PORT=' . $quote((string)$redis['port']) . "\n"
            . 'REDIS_PASSWORD=' . $quote($redis['password']) . "\n"
            . 'REDIS_DB=' . $quote((string)$redis['db']) . "\n";
        $envFile   = $this->baseDir() . '/.env';
        $temporary = $envFile . '.installing';
        if (file_put_contents($temporary, $content, LOCK_EX) === false || !@rename($temporary, $envFile)) {
            @unlink($temporary);
            throw new RuntimeException('无法写入 .env 环境配置文件，请检查项目目录权限');
        }
    }

    private function writeInstallLock(): void
    {
        $lockFile = $this->lockFile($this->baseDir());
        if (file_put_contents($lockFile, date('c') . "\nSTATUS=INSTALLED_AND_LOCKED\n", LOCK_EX) === false) {
            throw new RuntimeException('无法创建 install.lock，请检查项目根目录权限');
        }
    }

    private function applicationUrl(string $url): string
    {
        $url   = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if ($url === '' || $parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('请填写可公开访问的站点地址，例如 https://pay.example.com');
        }
        return $url;
    }

    private function isStrongPassword(string $password): bool
    {
        $classes = 0;
        foreach (['/[a-z]/', '/[A-Z]/', '/\d/', '/[^a-zA-Z\d]/'] as $pattern) {
            $classes += preg_match($pattern, $password) === 1 ? 1 : 0;
        }
        return strlen($password) >= 12 && strlen($password) <= 200 && $classes >= 3;
    }

    private function requestParams(object $request): array
    {
        if (method_exists($request, 'post') && ($params = $request->post())) {
            return $params;
        }
        $raw = (string)@file_get_contents('php://input');
        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $json;
            }
            parse_str($raw, $parsed);
            if ($parsed !== []) {
                return $parsed;
            }
        }
        return $_POST ?: $_GET;
    }

    private function databaseErrorMessage(Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, 'Access denied')) {
            return '数据库登录失败：请检查用户名、密码及该账号的访问权限。';
        }
        if (str_contains($message, 'Connection refused') || str_contains($message, "Can't connect")) {
            return '无法连接数据库：请检查主机、端口、MySQL 服务状态和防火墙。';
        }
        if (str_contains($message, 'CREATE command denied')) {
            return '数据库账号没有建库权限：请先在宝塔或数据库面板手动创建一个空数据库，并将该账号的所有权限授予新库，然后再重新测试。';
        }
        return '数据库检测失败：' . $e->getMessage();
    }

    private function installErrorMessage(Throwable $e): string
    {
        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }
        return '安装未完成，请检查运行目录权限、数据库权限和运行日志后重试。';
    }

    private function fixHints(array $extensions, array $permissions, bool $autoloadReady, bool $webmanProcess): array
    {
        $hints = [];
        if (!$autoloadReady) {
            $hints[] = '依赖未安装：在项目根目录执行 composer install --no-dev --optimize-autoloader';
        }
        foreach ($extensions as $name => $loaded) {
            if (!$loaded) {
                $hints[] = '缺少 PHP 扩展：' . $name . '，请在宝塔"软件商店 → PHP 设置 → 安装扩展"中启用。';
            }
        }
        if (!$permissions['all_perms_ok']) {
            $hints[] = '目录权限不足：请将项目文件所有者设为网站运行用户，并确保 runtime 与 .env 可写。';
        }
        if (!$webmanProcess) {
            $hints[] = '当前请求不是 Webman 常驻进程处理。安装完成后请启动 php start.php start，并让 Nginx 反向代理到 127.0.0.1:8787。';
        }
        return $hints;
    }

    private function isInstalled(): bool
    {
        return file_exists($this->lockFile($this->baseDir()));
    }

    private function baseDir(): string
    {
        return rtrim(function_exists('base_path') ? base_path() : dirname(__DIR__, 3), '/\\');
    }

    private function lockFile(string $baseDir): string
    {
        return (string)config('app.install_lock', $baseDir . '/install.lock');
    }
}
