<?php
/**
 * xlpay 独立安装向导（原生 PHP，不启动框架）
 * 访问 /install/  —— nginx 直连 php-fpm，绕过 SPA/白名单。
 * 步骤：环境检测 → 数据库 → 管理员 → Swoole加密 → 完成。
 * 安全：public/install.lock == install-end 时锁死"配库/建管理员/完成"等写操作。
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('X-Robots-Tag: noindex, nofollow', true);
date_default_timezone_set('Asia/Shanghai');

define('PUB_DIR', dirname(__DIR__));          // .../public
define('ROOT_DIR', dirname(PUB_DIR));         // 项目根
define('LOCK_FILE', PUB_DIR . '/install.lock');
define('SEED_SQL', ROOT_DIR . '/install_res/xlpay.sql');
define('ENV_FILE', ROOT_DIR . '/.env');

function is_locked(): bool
{
    return is_file(LOCK_FILE) && trim((string)@file_get_contents(LOCK_FILE)) === 'install-end';
}
function jout(bool $ok, string $msg, array $data = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function post(string $k, string $def = ''): string
{
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $def;
}
/** 用 POST 的库参数建 PDO 连接（可选指定库） */
function db_connect(bool $withDb = true): PDO
{
    $dsn = 'mysql:host=' . post('hostname', '127.0.0.1') . ';port=' . post('hostport', '3306');
    if ($withDb) $dsn .= ';dbname=' . post('database', 'xlpay');
    $dsn .= ';charset=utf8mb4';
    $pdo = new PDO($dsn, post('username', 'root'), post('password'), [
        PDO::ATTR_TIMEOUT            => 5,   // 连接超时，避免不可达主机长时间卡住
        PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
    return $pdo;
}
/** 简易 SQL 分句：按行累积，直到行尾以 ; 结束 */
function split_sql(string $sql): array
{
    $out = [];
    $buf = '';
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, '--')) continue;
        $buf .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            $out[] = trim($buf);
            $buf = '';
        }
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

$action = $_REQUEST['action'] ?? '';

if ($action !== '') {
    @set_time_limit(25);          // 兜底：单个动作最多跑 25s，避免 php-fpm worker 被长期占用
    ignore_user_abort(false);     // 前端 abort（超时/离开）时立即中断，及时释放 worker
    try {
        switch ($action) {

            // ---- 环境检测（只读，任何时候可用）----
            case 'env': {
                $items = [];
                $add = function ($name, $ok, $tip = '', $must = true) use (&$items) {
                    $items[] = ['name' => $name, 'ok' => (bool)$ok, 'tip' => (string)$tip, 'must' => (bool)$must];
                };
                $add('PHP 版本 ≥ 8.2', version_compare(PHP_VERSION, '8.2.0', '>='), '当前 ' . PHP_VERSION);
                $add('64 位 PHP', PHP_INT_SIZE === 8, (PHP_INT_SIZE * 8) . ' 位');
                foreach (['pdo_mysql', 'curl', 'openssl', 'gd', 'bcmath', 'mbstring', 'iconv', 'json', 'fileinfo', 'zip'] as $ext) {
                    $add('扩展 ' . $ext, extension_loaded($ext));
                }
                $add('gd 支持 freetype', function_exists('imagettftext'), '', false);
                foreach ([
                    '根目录可写(.env)' => ROOT_DIR,
                    'config/ 可写'     => ROOT_DIR . '/config',
                    'runtime/ 可写'    => ROOT_DIR . '/runtime',
                    'public/storage/ 可写' => PUB_DIR . '/storage',
                ] as $label => $dir) {
                    $add($label, is_dir($dir) && is_writable($dir), $dir);
                }
                $pass = true;
                foreach ($items as $it) if ($it['must'] && !$it['ok']) $pass = false;
                jout(true, '', ['items' => $items, 'pass' => $pass, 'installed' => is_locked()]);
            }

            // ---- 检测 swoole_loader 是否已安装（只读）----
            case 'swoole': {
                $loaded = extension_loaded('swoole_loader');
                // 二次校验：CLI php -m（fpm 未重载到的场景，worker 走 php-cli.ini）
                if (!$loaded && function_exists('proc_open')) {
                    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
                    if (!in_array('proc_open', $disabled, true)) {
                        $mm = implode('', array_slice(explode('.', PHP_VERSION), 0, 2));
                        $phpbin = '/www/server/php/' . $mm . '/bin/php';
                        if (is_file($phpbin)) {
                            $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
                            $p = @proc_open(escapeshellarg($phpbin) . ' -m 2>/dev/null', $desc, $pipes);
                            if (is_resource($p)) {
                                stream_set_blocking($pipes[1], false);
                                $out = '';
                                $deadline = time() + 6;
                                do {
                                    $out .= (string)stream_get_contents($pipes[1]);
                                    $st = proc_get_status($p);
                                    if (!$st['running']) { $out .= (string)stream_get_contents($pipes[1]); break; }
                                    if (time() >= $deadline) { proc_terminate($p, 9); break; }
                                    usleep(100000);
                                } while (true);
                                fclose($pipes[1]);
                                fclose($pipes[2]);
                                proc_close($p);
                                if (preg_match('/^swoole_loader$/mi', $out)) $loaded = true;
                            }
                        }
                    }
                }
                jout(true, '', ['loaded' => $loaded]);
            }

            // ---- 测试数据库（只读）----
            case 'testdb': {
                $pdo = db_connect(false);
                $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
                $db  = post('database', 'xlpay');
                $exists = (bool)$pdo->query("SHOW DATABASES LIKE " . $pdo->quote($db))->fetchColumn();
                $hasTables = false;
                if ($exists) {
                    $pdo->exec("USE `$db`");
                    $prefix = post('prefix', 'ba_');
                    $hasTables = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($prefix . 'admin'))->fetchColumn();
                }
                jout(true, '连接成功', ['version' => $ver, 'db_exists' => $exists, 'has_tables' => $hasTables]);
            }

            // ---- 建库 + 导入种子 + 写 .env（写操作，锁后禁止）----
            case 'installdb': {
                if (is_locked()) jout(false, '系统已安装（install.lock），如需重装请先删除该锁文件');
                $db     = post('database', 'xlpay');
                $prefix = post('prefix', 'ba_');
                $pdo    = db_connect(false);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                $pdo->exec("USE `$db`");
                $had = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($prefix . 'admin'))->fetchColumn();
                $imported = 0;
                if (!$had) {
                    if (!is_file(SEED_SQL)) jout(false, '内置种子缺失：' . SEED_SQL);
                    $sql = (string)file_get_contents(SEED_SQL);
                    if ($prefix !== 'ba_') $sql = str_replace('`ba_', '`' . $prefix, $sql);
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                    foreach (split_sql($sql) as $stmt) {
                        if (preg_match('/^(SET|\/\*)/i', $stmt)) continue;
                        $pdo->exec($stmt);
                        $imported++;
                    }
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                }
                write_env($db, $prefix);
                jout(true, $had ? '数据表已存在，已写入 .env 配置' : "建库并导入完成（执行 $imported 条）", ['had' => $had]);
            }

            // ---- 建/重置管理员（写操作，锁后禁止）----
            case 'admin': {
                if (is_locked()) jout(false, '系统已安装，管理员设置已锁定');
                $un = post('admin_user', 'admin');
                $pw = post('admin_pass');
                if ($un === '' || strlen($pw) < 6) jout(false, '管理员账号必填、密码至少 6 位');
                // 用户名格式：至少 5 位，且必须包含字母（可含数字/下划线）
                if (strlen($un) < 5 || !preg_match('/[a-zA-Z]/', $un) || !preg_match('/^[a-zA-Z0-9_]+$/', $un)) {
                    jout(false, '管理员用户名至少 5 位，须包含字母（仅允许字母、数字、下划线）');
                }
                $prefix = post('prefix', 'ba_');
                $pdo = db_connect(true);
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                $now  = time();
                $tbl  = $prefix . 'admin';
                $exist = $pdo->query("SELECT id FROM `$tbl` WHERE username=" . $pdo->quote($un))->fetchColumn();
                if ($exist) {
                    $st = $pdo->prepare("UPDATE `$tbl` SET password=?, salt='', status='enable', update_time=? WHERE id=?");
                    $st->execute([$hash, $now, $exist]);
                    $uid = (int)$exist;
                } else {
                    $st = $pdo->prepare("INSERT INTO `$tbl` (username,nickname,avatar,email,mobile,login_failure,last_login_ip,password,salt,motto,status,create_time,update_time) VALUES (?,?,'','','',0,'',?,'','','enable',?,?)");
                    $st->execute([$un, $un, $hash, $now, $now]);
                    $uid = (int)$pdo->lastInsertId();
                }
                // 关联超管组(1)
                $ga = $prefix . 'admin_group_access';
                $pdo->exec("DELETE FROM `$ga` WHERE uid=$uid");
                $pdo->exec("INSERT INTO `$ga` (uid,group_id) VALUES ($uid,1)");
                jout(true, '管理员已设置');
            }

            // ---- 云端授权：保存授权密钥 + 授权域名（写操作，锁后禁止）----
            case 'cloud': {
                if (is_locked()) jout(false, '系统已安装，云端授权请在后台「云端授权」中修改');
                $authcode = post('cloud_authcode');
                $domain   = strtolower(explode(':', post('cloud_domain'))[0]);
                $cloudUrl = post('cloud_url');
                if ($authcode === '') jout(false, '请填写云端授权密钥');
                if ($domain === '')   jout(false, '未识别到授权域名，请手动填写');
                $prefix = post('prefix', 'ba_');
                $pdo = db_connect(true);
                $tbl = $prefix . 'cloud_setting';
                cloud_set($pdo, $tbl, 'cloud_authcode', $authcode);
                cloud_set($pdo, $tbl, 'cloud_domain', $domain);
                if ($cloudUrl !== '') cloud_set($pdo, $tbl, 'cloud_url', rtrim($cloudUrl, '/') . '/');
                jout(true, '云端授权已保存（域名：' . $domain . '）');
            }

            // ---- 完成：写锁 ----
            case 'finish': {
                if (is_locked()) jout(true, '系统已安装');
                @file_put_contents(LOCK_FILE, 'install-end');
                jout(true, '安装完成');
            }

            default:
                jout(false, '未知操作');
        }
    } catch (Throwable $e) {
        jout(false, $e->getMessage());
    }
}

/** 生成/覆盖 .env（TP8 格式，仅数据库段 + 关闭 debug） */
function write_env(string $db, string $prefix): void
{
    $env = "APP_DEBUG = false\n\n"
        . "[DATABASE]\n"
        . "TYPE = mysql\n"
        . "HOSTNAME = " . post('hostname', '127.0.0.1') . "\n"
        . "DATABASE = " . $db . "\n"
        . "USERNAME = " . post('username', 'root') . "\n"
        . "PASSWORD = " . post('password') . "\n"
        . "HOSTPORT = " . post('hostport', '3306') . "\n"
        . "CHARSET = utf8mb4\n"
        . "PREFIX = " . $prefix . "\n";
    @file_put_contents(ENV_FILE, $env);
}

/** 云端设置键值表 upsert（ba_cloud_setting: name/value/update_time） */
function cloud_set(PDO $pdo, string $tbl, string $name, string $value): void
{
    $st = $pdo->prepare("SELECT id FROM `$tbl` WHERE name=? LIMIT 1");
    $st->execute([$name]);
    $id = $st->fetchColumn();
    if ($id) {
        $u = $pdo->prepare("UPDATE `$tbl` SET value=?, update_time=? WHERE id=?");
        $u->execute([$value, time(), $id]);
    } else {
        $i = $pdo->prepare("INSERT INTO `$tbl` (name,value,update_time) VALUES (?,?,?)");
        $i->execute([$name, $value, time()]);
    }
}

// ========================= GET 渲染向导 =========================
$installed = is_locked();
$curDomain = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')))[0]);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>xlpay 系统安装向导</title>
<style>
:root{--pri:#3b82f6;--ok:#16a34a;--no:#dc2626;--bg:#f5f7fb;--card:#fff;--bd:#e5e7eb;--mut:#6b7280}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;background:var(--bg);color:#111827}
.wrap{max-width:820px;margin:32px auto;padding:0 16px}
.brand{text-align:center;margin-bottom:18px}.brand h1{margin:0;font-size:24px}.brand p{margin:6px 0 0;color:var(--mut);font-size:13px}
.steps{display:flex;gap:8px;margin-bottom:18px}
.steps .s{flex:1;text-align:center;padding:10px 6px;background:var(--card);border:1px solid var(--bd);border-radius:10px;font-size:13px;color:var(--mut)}
.steps .s.active{border-color:var(--pri);color:var(--pri);font-weight:600}
.steps .s.done{border-color:var(--ok);color:var(--ok)}
.card{background:var(--card);border:1px solid var(--bd);border-radius:14px;padding:22px}
.card h2{margin:0 0 14px;font-size:18px}
.env-list{list-style:none;margin:0;padding:0}
.env-list li{display:flex;justify-content:space-between;align-items:center;padding:9px 4px;border-bottom:1px dashed var(--bd);font-size:14px}
.env-list li:last-child{border-bottom:none}
.badge{font-size:12px;padding:2px 10px;border-radius:20px}
.badge.ok{background:#dcfce7;color:var(--ok)}.badge.no{background:#fee2e2;color:var(--no)}.badge.warn{background:#fef9c3;color:#a16207}
.env-list .tip{color:var(--mut);font-size:12px;margin-left:8px}
.form-row{margin:12px 0}.form-row label{display:block;font-size:13px;color:var(--mut);margin-bottom:5px}
.form-row input{width:100%;padding:10px 12px;border:1px solid var(--bd);border-radius:9px;font-size:14px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btns{margin-top:18px;display:flex;gap:10px;justify-content:flex-end}
.btn{padding:10px 20px;border:none;border-radius:9px;font-size:14px;cursor:pointer;background:var(--pri);color:#fff}
.btn.ghost{background:#eef2ff;color:var(--pri)}.btn:disabled{opacity:.5;cursor:not-allowed}
.msg{margin-top:12px;font-size:13px;padding:10px 12px;border-radius:9px;display:none}
.msg.ok{display:block;background:#dcfce7;color:var(--ok)}.msg.no{display:block;background:#fee2e2;color:var(--no)}
.alert{background:#fffbeb;border:1px solid #fcd34d;color:#92400e;padding:12px 14px;border-radius:10px;font-size:13px;margin-bottom:14px}
.hide{display:none}
.done-box{text-align:center;padding:20px}.done-box .ic{font-size:44px}
a.link{color:var(--pri);text-decoration:none}
code{background:#f3f4f6;padding:2px 6px;border-radius:5px;font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><h1>XLPAY 系统安装向导</h1><p>聚合支付系统 · 引导安装 / 环境检测 / Swoole 加密</p></div>

  <!-- 欢迎 -->
  <div class="card" id="welcome">
    <div style="text-align:center;padding:30px 10px">
      <div style="font-size:48px;line-height:1">🎉</div>
      <h2 style="margin:14px 0 6px">感谢您选择本系统</h2>
      <p style="color:#8a94a6;margin:0 0 26px">准备开启不一样的支付体验吧</p>
      <button class="btn" onclick="showRewrite()">开始安装</button>
    </div>
  </div>

  <!-- 伪静态 -->
  <div class="card hide" id="rewrite">
    <h2>请先设置伪静态</h2>
    <p style="color:#8a94a6">在站点「伪静态 / Nginx」配置中加入以下规则并保存，否则后台与接口将无法正常访问：</p>
    <pre style="background:#0f1522;color:#c8d3e6;padding:14px;border-radius:10px;overflow:auto;font-size:13px;line-height:1.7;white-space:pre">location ~* (runtime|application)/{
	return 403;
}
location / {
	if (!-e $request_filename){
		rewrite  ^(.*)$  /index.php?s=$1  last;   break;
	}
}</pre>
    <div class="btns"><button class="btn ghost" onclick="backWelcome()">上一步</button><button class="btn" onclick="startWizard()">我已设置，下一步</button></div>
  </div>

  <div class="steps" id="stepsBar">
    <div class="s active" data-step="1">1 环境检测</div>
    <div class="s" data-step="2">2 数据库</div>
    <div class="s" data-step="3">3 管理员</div>
    <div class="s" data-step="4">4 Swoole加密</div>
    <div class="s" data-step="5">5 云端授权</div>
    <div class="s" data-step="6">6 完成</div>
  </div>

  <?php if ($installed): ?>
  <div class="alert">系统已安装（检测到 <code>public/install.lock</code>）。配库 / 建管理员 等写操作已锁定。如需重新安装，请先删除该锁文件。Swoole 加密工具不受影响，可直接前往。</div>
  <?php endif; ?>

  <!-- 步骤1 环境检测 -->
  <div class="card step" id="step1">
    <h2>环境检测</h2>
    <ul class="env-list" id="envList"><li>检测中…</li></ul>
    <div class="btns"><button class="btn" id="btnEnvNext" disabled>下一步：数据库</button></div>
  </div>

  <!-- 步骤2 数据库 -->
  <div class="card step hide" id="step2">
    <h2>数据库配置</h2>
    <div class="grid2">
      <div class="form-row"><label>数据库地址</label><input id="db_hostname" value="127.0.0.1"></div>
      <div class="form-row"><label>端口</label><input id="db_hostport" value="3306"></div>
      <div class="form-row"><label>数据库名</label><input id="db_database" value="xlpay"></div>
      <div class="form-row"><label>表前缀</label><input id="db_prefix" value="ba_"></div>
      <div class="form-row"><label>用户名</label><input id="db_username" value="root"></div>
      <div class="form-row"><label>密码</label><input id="db_password" type="password" placeholder="数据库密码"></div>
    </div>
    <div class="msg" id="dbMsg"></div>
    <div class="btns">
      <button class="btn ghost" onclick="go(1)">上一步</button>
      <button class="btn ghost" id="btnTest">测试连接</button>
      <button class="btn ghost hide" id="btnSkipDb">跳过数据库安装</button>
      <button class="btn" id="btnInstallDb" disabled>建库并安装</button>
    </div>
  </div>

  <!-- 步骤3 管理员 -->
  <div class="card step hide" id="step3">
    <h2>管理员账号</h2>
    <div class="grid2">
      <div class="form-row"><label>管理员账号（≥5位，含字母）</label><input id="admin_user" value="admin"></div>
      <div class="form-row"><label>登录密码（≥6位）</label><input id="admin_pass" type="password"></div>
    </div>
    <div class="msg" id="adminMsg"></div>
    <div class="btns">
      <button class="btn ghost" onclick="go(2)">上一步</button>
      <button class="btn" id="btnAdmin">保存并继续</button>
    </div>
  </div>

  <!-- 步骤4 Swoole -->
  <div class="card step hide" id="step4">
    <h2>Swoole 加密扩展（swoole_loader）</h2>
    <p style="color:var(--mut);font-size:13px">本系统核心代码已加密，<b style="color:var(--no)">必须</b>安装 swoole_loader 扩展才能正常运行。请先用安装工具装好扩展，再点「重新检测」继续。</p>
    <div class="msg" id="swMsg"></div>
    <div class="btns">
      <button class="btn ghost" onclick="go(3)">上一步</button>
      <a class="btn ghost" href="swoole/" target="_blank">打开 Swoole 加密安装工具 →</a>
      <button class="btn ghost" id="btnSwCheck">重新检测</button>
      <button class="btn" id="btnSwNext" disabled>下一步：云端授权</button>
    </div>
  </div>

  <!-- 步骤5 云端授权 -->
  <div class="card step hide" id="step5">
    <h2>云端授权</h2>
    <p style="color:var(--mut);font-size:13px">填写云端下发的授权密钥，绑定当前站点域名。未配置也可跳过（云端功能默认不拦截），之后可在后台「云端授权」中补填。</p>
    <div class="form-row">
      <label>授权域名（已自动识别当前访问域名，如有多域名请填云端登记的授权域名）</label>
      <input id="cloud_domain" value="<?= htmlspecialchars($curDomain) ?>">
    </div>
    <div class="form-row">
      <label>授权密钥（authcode）</label>
      <input id="cloud_authcode" placeholder="粘贴云端下发的授权密钥">
    </div>
    <details style="margin:6px 0 2px">
      <summary style="cursor:pointer;color:var(--pri);font-size:13px">高级：自定义授权站地址（可选）</summary>
      <div class="form-row" style="margin-top:10px">
        <label>授权站 URL（留空使用默认 https://cloud.iosle.com/）</label>
        <input id="cloud_url" placeholder="https://cloud.iosle.com/">
      </div>
    </details>
    <div class="msg" id="cloudMsg"></div>
    <div class="btns">
      <button class="btn ghost" onclick="go(4)">上一步</button>
      <button class="btn ghost" id="btnCloudSkip">跳过此步</button>
      <button class="btn" id="btnCloudSave">保存并继续</button>
    </div>
  </div>

  <!-- 步骤6 完成 -->
  <div class="card step hide" id="step6">
    <div class="done-box">
      <div class="ic">🎉</div>
      <h2>安装完成</h2>
      <p style="color:var(--mut)">已写入 <code>.env</code> 与安装锁。请立即删除或改名 <code>public/install/</code> 目录以确保安全。</p>
      <div class="msg" id="finMsg"></div>
      <div class="btns" style="justify-content:center">
        <button class="btn" id="btnFinish">写入完成标记</button>
        <a class="btn ghost" href="/" >前往首页</a>
      </div>
    </div>
  </div>
</div>

<script>
var INSTALLED = <?= $installed ? 'true' : 'false' ?>;
function $(s){return document.querySelector(s)}
function dbData(extra){
  var d = Object.assign({
    hostname:$('#db_hostname').value, hostport:$('#db_hostport').value,
    database:$('#db_database').value, prefix:$('#db_prefix').value,
    username:$('#db_username').value, password:$('#db_password').value
  }, extra||{});
  return d;
}
function api(action, data, timeoutMs){
  var ctrl = ('AbortController' in window) ? new AbortController() : null;
  var timer = ctrl ? setTimeout(function(){ ctrl.abort(); }, timeoutMs || 12000) : null;
  var body = new URLSearchParams(Object.assign({action:action}, data||{}));
  var opt = {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body};
  if(ctrl) opt.signal = ctrl.signal;
  return fetch(location.pathname, opt)
    .then(function(r){ return r.json(); })
    .catch(function(err){
      if(err && err.name === 'AbortError') return {ok:false, msg:'检测超时（服务器无响应），请稍后重试或检查数据库地址/网络', _timeout:true};
      return {ok:false, msg:'请求失败：' + ((err && err.message) || err)};
    })
    .then(function(res){ if(timer) clearTimeout(timer); return res; });
}
function go(step){
  document.querySelectorAll('.step').forEach(function(e){e.classList.add('hide')});
  $('#step'+step).classList.remove('hide');
  document.querySelectorAll('.steps .s').forEach(function(e){
    var s=+e.dataset.step; e.classList.toggle('active', s===step); e.classList.toggle('done', s<step);
  });
  if(step===4) checkSwoole();   // 进入 Swoole 步即强制检测
}
function showMsg(id, ok, text){ var m=$(id); m.className='msg '+(ok?'ok':'no'); m.textContent=text; }

// 步骤1
function loadEnv(){
  var ul=$('#envList');
  ul.innerHTML='<li><span>检测中…</span></li>';
  $('#btnEnvNext').disabled=true;
  api('env', null, 10000).then(function(res){
    if(!res || !res.ok || !res.data){
      ul.innerHTML='<li><span style="color:var(--no)">'+((res&&res.msg)||'环境检测失败')+'</span>'
        +'<button class="btn ghost" onclick="loadEnv()">重试</button></li>';
      return;
    }
    var d=res.data; ul.innerHTML='';
    d.items.forEach(function(it){
      var b = it.ok?'<span class="badge ok">通过</span>':(it.must?'<span class="badge no">缺失</span>':'<span class="badge warn">建议</span>');
      ul.innerHTML += '<li><span>'+it.name+(it.tip?' <span class="tip">'+it.tip+'</span>':'')+'</span>'+b+'</li>';
    });
    $('#btnEnvNext').disabled = !d.pass;
  });
}
$('#btnEnvNext').onclick=function(){ go(2) };

// 步骤2
function busy(btn, on, txt){
  if(on){ btn._old = btn.textContent; btn.disabled = true; btn.textContent = txt || '处理中…'; }
  else  { btn.disabled = false; if(btn._old!=null) btn.textContent = btn._old; }
}
$('#btnTest').onclick=function(){
  var b=this; busy(b, true, '连接中…');
  api('testdb', dbData(), 8000).then(function(res){
    showMsg('#dbMsg', res.ok, res.ok ? ('连接成功 · MySQL '+res.data.version+(res.data.has_tables?'（已有数据表，可直接跳过数据库安装）':'')) : res.msg);
    $('#btnInstallDb').disabled = !res.ok || INSTALLED;
    // 已有数据表 或 系统已安装 → 提供跳过
    if(res.ok && (res.data.has_tables || INSTALLED)) $('#btnSkipDb').classList.remove('hide');
  }).then(function(){ busy(b, false); });
};
$('#btnSkipDb').onclick=function(){
  // 跳过数据库安装：不改动数据库，直接进入下一步（已安装则直达 Swoole）
  go(INSTALLED ? 4 : 3);
};
$('#btnInstallDb').onclick=function(){
  if(INSTALLED){ showMsg('#dbMsg',false,'系统已安装，写操作已锁定'); return; }
  var b=this; busy(b, true, '安装中…');
  api('installdb', dbData(), 60000).then(function(res){
    showMsg('#dbMsg', res.ok, res.msg);
    if(res.ok){ setTimeout(function(){ go(3) }, 700); }
    else { busy(b, false); }
  });
};

// 步骤3
$('#btnAdmin').onclick=function(){
  if(INSTALLED){ showMsg('#adminMsg',false,'系统已安装，管理员设置已锁定'); return; }
  var un=$('#admin_user').value;
  if(!/^[a-zA-Z0-9_]+$/.test(un) || un.length<5 || !/[a-zA-Z]/.test(un)){
    showMsg('#adminMsg', false, '管理员用户名至少 5 位，须包含字母（仅允许字母、数字、下划线）'); return;
  }
  var b=this; busy(b, true, '保存中…');
  api('admin', dbData({admin_user:$('#admin_user').value, admin_pass:$('#admin_pass').value}), 12000).then(function(res){
    showMsg('#adminMsg', res.ok, res.msg);
    if(res.ok){ setTimeout(function(){ go(4) }, 700); }
    else { busy(b, false); }
  });
};

// 步骤4 Swoole 加密扩展（强制）
function checkSwoole(){
  var m=$('#swMsg'); m.className='msg'; m.style.display='block'; m.textContent='正在检测 swoole_loader…';
  $('#btnSwNext').disabled = true;
  var b=$('#btnSwCheck'); busy(b, true, '检测中…');
  api('swoole', null, 10000).then(function(res){
    var loaded = res && res.data && res.data.loaded;
    if(loaded){
      m.className='msg ok'; m.textContent='✅ swoole_loader 已安装并加载，可继续。';
      $('#btnSwNext').disabled = false;
    } else {
      m.className='msg no';
      m.textContent = (res && res._timeout) ? res.msg
        : '未检测到 swoole_loader。请点上方「打开 Swoole 加密安装工具」完成安装后，再点「重新检测」。';
      $('#btnSwNext').disabled = true;
    }
  }).then(function(){ busy(b, false); });
}
$('#btnSwCheck').onclick=checkSwoole;
$('#btnSwNext').onclick=function(){ go(5) };

// 步骤5 云端授权
function cloudData(){
  return dbData({
    cloud_domain:   $('#cloud_domain').value,
    cloud_authcode: $('#cloud_authcode').value,
    cloud_url:      $('#cloud_url').value
  });
}
$('#btnCloudSkip').onclick=function(){ go(6); };
$('#btnCloudSave').onclick=function(){
  if(INSTALLED){ showMsg('#cloudMsg', false, '系统已安装，云端授权请在后台「云端授权」中修改'); return; }
  if(!$('#cloud_authcode').value.trim()){ showMsg('#cloudMsg', false, '请填写授权密钥，或点「跳过此步」'); return; }
  var b=this; busy(b, true, '保存中…');
  api('cloud', cloudData(), 12000).then(function(res){
    showMsg('#cloudMsg', res.ok, res.msg);
    if(res.ok){ setTimeout(function(){ go(6) }, 700); }
    else { busy(b, false); }
  });
};

// 步骤6 完成
$('#btnFinish').onclick=function(){
  var b=this; busy(b, true, '写入中…');
  api('finish', null, 8000).then(function(res){ showMsg('#finMsg', res.ok, res.msg); busy(b, false); });
};

// 初始只显示欢迎页，隐藏向导（步骤条 + 各步卡片 + 已安装提示）
var INSTALLED_ALERT = document.querySelector('.wrap > .alert');
$('#stepsBar').classList.add('hide');
document.querySelectorAll('.card.step').forEach(function(c){ c.classList.add('hide'); });
if(INSTALLED_ALERT) INSTALLED_ALERT.classList.add('hide');

function showRewrite(){ $('#welcome').classList.add('hide'); $('#rewrite').classList.remove('hide'); }
function backWelcome(){ $('#rewrite').classList.add('hide'); $('#welcome').classList.remove('hide'); }
function startWizard(){
  $('#welcome').classList.add('hide'); $('#rewrite').classList.add('hide');
  $('#stepsBar').classList.remove('hide');
  if(INSTALLED_ALERT) INSTALLED_ALERT.classList.remove('hide');
  go(1); loadEnv();
  if(INSTALLED){ $('#btnSkipDb').classList.remove('hide'); }
}
</script>
</body>
</html>
