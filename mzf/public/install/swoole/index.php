<?php
/**
 * Swoole Compiler Loader 自动安装页（原生 PHP）
 * 访问 /install/swoole/
 * - 探测：PHP 版本 / 线程安全 / 扩展目录 / php.ini / 是否已加载 / 互斥扩展 / 内置 .so 是否就绪
 * - 安装：优先「一键」(需 sudoers 免密) ，否则给「root 命令」让用户在终端执行
 * 安装本质需 root（写扩展目录/改 php.ini/reload），网页跑在 www。
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('X-Robots-Tag: noindex, nofollow', true);

define('SW_DIR', __DIR__);
define('SCRIPT', SW_DIR . '/loader_install.sh');

/** 在 www 下执行命令（proc_open，绕开被禁用的 shell_exec）；不可用或超时则返回 null */
function run_cmd(string $cmd, int $timeout = 8): ?string
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('proc_open', $disabled, true) || !function_exists('proc_open')) {
        return null; // 无法执行命令 → 只能走 root 命令方式
    }
    // 双保险：coreutils timeout 存在则由内核层限时，避免 sudo/php 卡死
    if (@is_executable('/usr/bin/timeout') || @is_executable('/bin/timeout')) {
        $cmd = 'timeout ' . (int)$timeout . ' ' . $cmd;
    }
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($p)) return null;
    // 非阻塞读取 + 墙钟兜底：即便无 timeout 命令，PHP 侧也不会一直挂着
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out      = '';
    $deadline = time() + $timeout + 1;
    do {
        $read = [$pipes[1], $pipes[2]];
        $w = $e = null;
        if (@stream_select($read, $w, $e, 1) > 0) {
            foreach ($read as $r) $out .= (string)stream_get_contents($r);
        }
        $st = proc_get_status($p);
        if (!$st['running']) {
            // 读干净剩余缓冲
            $out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
            break;
        }
        if (time() >= $deadline) {
            proc_terminate($p, 9); // 超时强杀
            break;
        }
    } while (true);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return $out;
}

/** 环境探测 */
function detect(): array
{
    $ver     = PHP_VERSION;                       // 8.2.31
    $mm      = implode('', array_slice(explode('.', $ver), 0, 2)); // 82
    $zts     = (defined('PHP_ZTS') && PHP_ZTS) ? true : false;
    $safe    = $zts ? 'zts' : 'nts';
    $so      = $zts ? "swoole_loader{$mm}_zts.so" : "swoole_loader{$mm}_nts.so";
    $extdir  = (string)ini_get('extension_dir');
    $ini     = (string)php_ini_loaded_file();
    $loaded  = extension_loaded('swoole_loader');
    // 互斥扩展
    $incompat = [];
    foreach (get_loaded_extensions() as $e) {
        foreach (['xdebug', 'ionCube', 'zend_loader'] as $bad) {
            if (stripos($e, $bad) !== false) $incompat[] = $e;
        }
    }
    return [
        'os'        => php_uname('s') . ' ' . php_uname('m'),
        'php'       => $ver,
        'bit'       => PHP_INT_SIZE * 8,
        'sapi'      => PHP_SAPI,
        'zts'       => $zts,
        'safe'      => $safe,
        'mm'        => $mm,
        'so'        => $so,
        'so_ready'  => is_file(SW_DIR . '/' . $so),
        'extdir'    => $extdir,
        'ini'       => $ini,
        'loaded'    => $loaded,
        'incompat'  => array_values(array_unique($incompat)),
    ];
}

/** 探测能否 sudo 免密执行安装脚本 */
function sudo_ready(string $mm): bool
{
    if (!is_file(SCRIPT)) return false;
    $cmd = 'sudo -n bash ' . escapeshellarg(SCRIPT) . ' ' . escapeshellarg($mm) . ' --check 2>&1';
    $out = run_cmd($cmd, 5);
    return is_string($out) && strpos($out, 'OK') !== false && stripos($out, 'sudo:') === false && stripos($out, 'password') === false;
}

$action = $_REQUEST['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $d = detect();
    if ($action === 'status') {
        // 二次校验：CLI php -m（反映写入 php-cli.ini 后的加载）
        $cliLoaded = false;
        $phpbin = '/www/server/php/' . $d['mm'] . '/bin/php';
        if (is_file($phpbin)) {
            $out = run_cmd(escapeshellarg($phpbin) . ' -m 2>/dev/null', 6);
            $cliLoaded = is_string($out) && preg_match('/^swoole_loader$/mi', $out);
        }
        $d['loaded'] = $d['loaded'] || $cliLoaded;
        $d['sudo']   = sudo_ready($d['mm']);
        echo json_encode(['ok' => true, 'data' => $d], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'install') {
        if ($d['loaded']) { echo json_encode(['ok' => true, 'msg' => 'swoole_loader 已加载']); exit; }
        if (!$d['so_ready']) { echo json_encode(['ok' => false, 'msg' => '内置扩展缺失：' . $d['so'] . '，请先放入本目录']); exit; }
        if (!sudo_ready($d['mm'])) { echo json_encode(['ok' => false, 'msg' => '未配置 sudoers 免密，请用下方 root 命令在终端执行']); exit; }
        $cmd = 'sudo -n bash ' . escapeshellarg(SCRIPT) . ' ' . escapeshellarg($d['mm']) . ' 2>&1';
        $out = (string)run_cmd($cmd, 40);
        $ok  = strpos($out, 'OK:') !== false;
        echo json_encode(['ok' => $ok, 'msg' => trim($out) ?: '执行无输出（proc_open 可能被禁用），请用 root 命令'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => false, 'msg' => '未知操作']);
    exit;
}

$d = detect();
$sudo = sudo_ready($d['mm']);
$rootCmd = 'sudo bash ' . SCRIPT . ' ' . $d['mm'];
$sudoersLine = 'www ALL=(root) NOPASSWD: ' . SCRIPT;
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Swoole 加密扩展安装</title>
<style>
:root{--pri:#3b82f6;--ok:#16a34a;--no:#dc2626;--bg:#f5f7fb;--card:#fff;--bd:#e5e7eb;--mut:#6b7280}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,"Microsoft YaHei",sans-serif;background:var(--bg);color:#111827}
.wrap{max-width:820px;margin:32px auto;padding:0 16px}
.brand{text-align:center;margin-bottom:18px}.brand h1{margin:0;font-size:22px}.brand p{margin:6px 0 0;color:var(--mut);font-size:13px}
.card{background:var(--card);border:1px solid var(--bd);border-radius:14px;padding:22px;margin-bottom:16px}
.card h2{margin:0 0 14px;font-size:17px}
ul.kv{list-style:none;margin:0;padding:0}
ul.kv li{display:flex;justify-content:space-between;padding:8px 4px;border-bottom:1px dashed var(--bd);font-size:14px}
ul.kv li:last-child{border-bottom:none}.kv .k{color:var(--mut)}
.badge{font-size:12px;padding:2px 10px;border-radius:20px}
.badge.ok{background:#dcfce7;color:var(--ok)}.badge.no{background:#fee2e2;color:var(--no)}.badge.warn{background:#fef9c3;color:#a16207}
.btn{padding:10px 20px;border:none;border-radius:9px;font-size:14px;cursor:pointer;background:var(--pri);color:#fff}
.btn.ghost{background:#eef2ff;color:var(--pri)}.btn:disabled{opacity:.5;cursor:not-allowed}
.btns{margin-top:16px;display:flex;gap:10px;flex-wrap:wrap}
.msg{margin-top:12px;font-size:13px;padding:10px 12px;border-radius:9px;white-space:pre-wrap;display:none}
.msg.ok{display:block;background:#dcfce7;color:var(--ok)}.msg.no{display:block;background:#fee2e2;color:var(--no)}
pre{background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:10px;font-size:13px;overflow:auto}
.alert{padding:12px 14px;border-radius:10px;font-size:13px;margin-bottom:14px}
.alert.warn{background:#fffbeb;border:1px solid #fcd34d;color:#92400e}
.alert.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
details{margin-top:10px}summary{cursor:pointer;color:var(--pri);font-size:13px}
a.link{color:var(--pri)}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><h1>Swoole 加密扩展安装（swoole_loader）</h1><p>运行加密后的核心代码所需扩展 · 自动/引导安装</p></div>

  <div class="card">
    <h2>当前环境</h2>
    <ul class="kv" id="envKv">
      <li><span class="k">操作系统</span><span><?= htmlspecialchars($d['os']) ?></span></li>
      <li><span class="k">PHP 版本</span><span><?= $d['php'] ?> (<?= $d['bit'] ?>位 / <?= $d['sapi'] ?>)</span></li>
      <li><span class="k">线程安全</span><span><?= $d['zts'] ? '线程安全 ZTS' : '非线程安全 NTS' ?></span></li>
      <li><span class="k">扩展目录</span><span><?= htmlspecialchars($d['extdir']) ?></span></li>
      <li><span class="k">php.ini</span><span><?= htmlspecialchars($d['ini']) ?></span></li>
      <li><span class="k">需要的扩展文件</span><span><?= $d['so'] ?> <?= $d['so_ready'] ? '<span class="badge ok">已内置</span>' : '<span class="badge no">未内置</span>' ?></span></li>
      <li><span class="k">swoole_loader</span><span id="loadedBadge"><?= $d['loaded'] ? '<span class="badge ok">已安装</span>' : '<span class="badge warn">未安装</span>' ?></span></li>
    </ul>
  </div>

  <?php if ($d['incompat']): ?>
  <div class="alert warn">检测到互斥扩展：<b><?= htmlspecialchars(implode(', ', $d['incompat'])) ?></b>。swoole_loader 与其不兼容，请先在 php.ini 中移除后重启 PHP。</div>
  <?php endif; ?>

  <?php if (!$d['so_ready']): ?>
  <div class="alert warn">未找到内置扩展文件 <code><?= $d['so'] ?></code>。请把与「PHP <?= $d['php'] ?> / <?= strtoupper($d['safe']) ?>」匹配的 <code><?= $d['so'] ?></code> 上传到目录 <code><?= SW_DIR ?>/</code> 后刷新本页。</div>
  <?php endif; ?>

  <div class="card" id="loadedOk" style="<?= $d['loaded'] ? '' : 'display:none' ?>">
    <div class="alert info" style="margin:0">✅ swoole_loader 已安装并加载，可运行加密核心代码。</div>
  </div>

  <div class="card" id="installBox" style="<?= $d['loaded'] ? 'display:none' : '' ?>">
    <h2>安装</h2>
    <?php if ($sudo): ?>
    <div class="alert info">已检测到免密提权，可直接一键安装。</div>
    <?php else: ?>
    <div class="alert info">网页运行在 www 用户，安装需 root。请用下方 <b>root 命令</b>在服务器终端（宝塔终端 / SSH）执行一次；或按「开启一键安装」配置后用按钮。</div>
    <?php endif; ?>

    <p style="font-size:13px;color:var(--mut);margin:6px 0">方式一 · root 命令（复制到终端以 root 执行）：</p>
    <pre id="rootCmd"><?= htmlspecialchars($rootCmd) ?></pre>

    <div class="btns">
      <button class="btn" id="btnOneClick" <?= ($sudo && $d['so_ready']) ? '' : 'disabled' ?>>一键安装<?= $sudo ? '' : '（需先开启）' ?></button>
      <button class="btn ghost" id="btnRecheck">我已执行 / 重新检测</button>
      <a class="btn ghost" href="../">返回安装向导</a>
    </div>
    <div class="msg" id="insMsg"></div>

    <details>
      <summary>如何开启网页「一键安装」（可选，需一次性 root 配置）</summary>
      <div style="font-size:13px;color:#374151;margin-top:8px">
        以 root 执行以下命令，把脚本设为 root 所有并加一条免密 sudoers 规则：
        <pre>chown root:root <?= SCRIPT ?>
chmod 755 <?= SCRIPT ?>
echo '<?= htmlspecialchars($sudoersLine) ?>' | sudo tee /etc/sudoers.d/xlpay_swoole
chmod 440 /etc/sudoers.d/xlpay_swoole</pre>
        配置后刷新本页即可看到「一键安装」可用。<b>安装完成后建议删除该 sudoers 规则与整个 <code>install/</code> 目录。</b>
      </div>
    </details>
  </div>

  <div class="alert warn">⚠ 安全：安装完成后请删除或改名 <code>public/install/</code> 目录，避免安装器暴露在公网。</div>
</div>

<script>
function $(s){return document.querySelector(s)}
function api(action, timeoutMs){
  var ctrl = ('AbortController' in window) ? new AbortController() : null;
  var timer = ctrl ? setTimeout(function(){ ctrl.abort(); }, timeoutMs || 15000) : null;
  var opt = {method:'POST'};
  if(ctrl) opt.signal = ctrl.signal;
  return fetch(location.pathname+'?action='+action, opt)
    .then(function(r){return r.json()})
    .catch(function(err){
      if(err && err.name === 'AbortError') return {ok:false, msg:'检测超时，请稍后重试'};
      return {ok:false, msg:'请求失败：' + ((err && err.message) || err)};
    })
    .then(function(res){ if(timer) clearTimeout(timer); return res; });
}
function refreshStatus(){
  api('status', 12000).then(function(res){
    var d=res.data;
    if(!d) return;
    var loaded = d.loaded;
    $('#loadedBadge').innerHTML = loaded ? '<span class="badge ok">已安装</span>' : '<span class="badge warn">未安装</span>';
    $('#loadedOk').style.display = loaded ? '' : 'none';
    $('#installBox').style.display = loaded ? 'none' : '';
    $('#btnOneClick').disabled = !(d.sudo && d.so_ready);
    $('#btnOneClick').textContent = d.sudo ? '一键安装' : '一键安装（需先开启）';
  });
}
$('#btnOneClick').onclick=function(){
  var b=this; b.disabled=true; var ot=b.textContent; b.textContent='安装中…';
  var m=$('#insMsg'); m.className='msg';
  api('install', 45000).then(function(res){
    m.className='msg '+(res.ok?'ok':'no'); m.textContent=res.msg||''; m.style.display='block';
    if(res.ok){ setTimeout(refreshStatus, 1500); } else { b.disabled=false; b.textContent=ot; }
  });
};
$('#btnRecheck').onclick=refreshStatus;
</script>
</body>
</html>
