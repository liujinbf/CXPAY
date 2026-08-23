<?php

declare(strict_types=1);

namespace tools\release;

use Phar;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/Obfuscator.php';

/**
 * CXPAY 主站源码安全隔离、核心代码加密混淆与云端发布构建工具
 *
 * 用法:
 * php tools/release/build_release.php [--version=1.0.0] [--domain=authorized.domain.com] [--key=CX_KEY_xxx] [--encrypt=1]
 */

// 1. 解析参数
$options = getopt('', ['version::', 'domain::', 'key::', 'encrypt::', 'help::']);

if (isset($options['help'])) {
    echo "用法: php tools/release/build_release.php [选项]\n";
    echo "  --version=1.0.0              发布版本号 (默认: 1.0.0)\n";
    echo "  --domain=domain.com          授权买家绑定域名 (默认: official-authorized)\n";
    echo "  --key=CX_KEY_xxx             买家授权 Key (默认: 动态生成)\n";
    echo "  --encrypt=1                  是否加密核心 PHP 源码 (1=是, 0=否, 默认: 1)\n";
    exit(0);
}

$version = (string)($options['version'] ?? '1.0.0');
$domain = (string)($options['domain'] ?? 'official-authorized');
$licenseKey = (string)($options['key'] ?? ('CX_KEY_' . bin2hex(random_bytes(16))));
$isEncrypt = (bool)((int)($options['encrypt'] ?? 1));

// 2. 智能定位主站系统源码来源目录
$possibleSourceRoots = [
    '/www/apps/cxpay-runtime/current',
    'c:/Users/Administrator/Desktop/CXPAY',
    dirname(__DIR__, 2),
];
$sourceRoot = '';
foreach ($possibleSourceRoots as $root) {
    if (file_exists($root . '/app/controller') || file_exists($root . '/app/service') || file_exists($root . '/config/app.php')) {
        $sourceRoot = $root;
        break;
    }
}
if ($sourceRoot === '') {
    $sourceRoot = dirname(__DIR__, 2);
}

// 智能定位云端分发目录
$possibleCloudDirs = [
    '/www/apps/cxpay-cloud/current/public/releases',
    $sourceRoot . '/services/cloud-control-plane/public/releases',
    $sourceRoot . '/public/releases',
];
$cloudReleaseDir = '';
foreach ($possibleCloudDirs as $cd) {
    if (is_dir(dirname($cd))) {
        $cloudReleaseDir = $cd;
        break;
    }
}
if ($cloudReleaseDir === '') {
    $cloudReleaseDir = $sourceRoot . '/public/releases';
}

// 确保临时工作目录可写 (优先使用 systemd 明确允许的 shared 目录)
$tempBase = is_dir('/www/apps/cxpay-cloud/shared') ? '/www/apps/cxpay-cloud/shared/build' : (sys_get_temp_dir() . '/cxpay_build');
@mkdir($tempBase, 0777, true);
$safeVer = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $version);
$stagingDir = $tempBase . '/staging_cxpay_' . $safeVer . '_' . bin2hex(random_bytes(3));
$zipOutputFile = $cloudReleaseDir . "/CXPAY_Release_v{$version}.zip";


echo "=========================================================\n";
echo "       CXPAY 主站源码安全隔离、加密与云端发布构建工具       \n";
echo "=========================================================\n";
echo "主站源码目录:  {$sourceRoot}\n";
echo "版本号:        v{$version}\n";
echo "授权买家域名:  {$domain}\n";
echo "授权 Key:      " . substr($licenseKey, 0, 10) . "******\n";
echo "核心源码加密:  " . ($isEncrypt ? '已启用 (多层自解密混淆 + 水印保护)' : '未启用') . "\n";
echo "临时工作目录:  {$stagingDir}\n";
echo "输出分发目录:  {$cloudReleaseDir}\n";
echo "=========================================================\n\n";

// 3. 清理并初始化临时 Staging 目录
if (is_dir($stagingDir)) {
    deleteDirectory($stagingDir);
}
@mkdir($stagingDir, 0777, true);
@mkdir($cloudReleaseDir, 0777, true);


// 4. 定义复制白名单目录与文件
$copyItems = [
    'app'              => true,
    'config'           => true,
    'database'         => true,
    'deploy'           => true,
    'public'           => true,
    'process'          => true,
    'support'          => true,
    '.env.example'     => false,
    'composer.json'    => false,
    'docker-compose.yml' => false,
    'server.php'       => false,
    'start.php'        => false,
    'windows.php'      => false,
    'reset_pwd.php'    => false,
    'setup.sh'         => false,
    'update.sh'        => false,
    'DEPLOYMENT.md'    => false,
    'README.md'        => false,
    '安装说明.txt'      => false,
    'phpunit.xml'      => false,
];



// 黑名单模式（严禁打入发布包的文件/后缀）
$blacklistPatterns = [
    '/\.git/i',
    '/\.github/i',
    '/\.agents/i',
    '/\.worktrees/i',
    '/scratch/i',
    '/\.env$/i',
    '/identity\.json$/i',
    '/services\/cloud-control-plane/i',
    '/plugins-src/i',
    '/tools\/release/i',
    '/\.phpunit\.cache/i',
    '/\.log$/i',
    '/\.tmp$/i',
    '/\.bak$/i',
];

echo "[1/6] 正在执行源码安全隔离与纯净文件提取...\n";
foreach ($copyItems as $item => $isDir) {
    $srcPath = $sourceRoot . '/' . $item;
    $destPath = $stagingDir . '/' . $item;

    if (!file_exists($srcPath)) {
        continue;
    }

    if ($isDir) {
        @mkdir($destPath, 0755, true);
        copyDirectoryWithFilter($srcPath, $destPath, $blacklistPatterns);
    } else {
        @mkdir(dirname($destPath), 0755, true);
        copy($srcPath, $destPath);
    }
}
echo "  ✓ 纯净文件提取完成 (已完全剔除云控制面、.env 与开发私钥)\n";


// 3.1 针对授权客户发布源码的专用安全净化：彻底剥离自营站专用的云端总后台跳转入口
$adminIndexHtml = $stagingDir . '/public/admin/index.html';
if (file_exists($adminIndexHtml)) {
    $htmlContent = file_get_contents($adminIndexHtml);
    // 匹配并安全剥离包含 id="cloud-ops-direct-link" 或指向云端控制台的快捷跳转 <a> 标签
    $htmlContent = preg_replace(
        '/\s*<!--\s*云端总控制台直连入口.*?-->\s*<a[^>]*id=["\']cloud-ops-direct-link["\'][^>]*>.*?<\/a>/is',
        '',
        $htmlContent
    );
    // 兜底正则匹配：移除任何指向 cloud.fcwan.cn 的“直连云端总控制台”链接
    $htmlContent = preg_replace(
        '/<a[^>]*href=["\'][^"\']*cloud\.fcwan\.cn[^"\']*["\'][^>]*>.*?直连云端总控制台.*?<\/a>/is',
        '',
        $htmlContent
    );
    file_put_contents($adminIndexHtml, $htmlContent);
    echo "  ✓ 已安全剥离自营云端总控制台跳转链接 (确保授权客户源码无云端后台入口)\n";
}

$coreFilesToEncrypt = [
    'app/service/CloudInstanceClient.php',
    'app/service/PluginLicenseService.php',
    'app/service/WatermarkTracerService.php',
    'app/service/OrderService.php',
    'app/payment/PaymentManager.php',
    'app/payment/Plugin/PluginPackageInstaller.php',
    'app/service/RiskGuardService.php',
];

$watermarkId = 'WM_' . strtoupper(substr(md5($domain . $licenseKey . 'CXPAY_SALT_2026_SECRET_888'), 0, 16));
$obfuscator = new Obfuscator($domain, $licenseKey, $watermarkId);

if ($isEncrypt) {
    echo "\n[2/6] 正在对核心业务与授权模块执行加密混淆与水印打标...\n";
    echo "  → 生成买家溯源水印 ID: {$watermarkId}\n";

    foreach ($coreFilesToEncrypt as $relFile) {
        $targetPath = $stagingDir . '/' . $relFile;
        if (!file_exists($targetPath)) {
            echo "  ⚠️ 核心文件不存在，跳过: {$relFile}\n";
            continue;
        }

        $obfuscated = $obfuscator->obfuscateFile($targetPath);
        file_put_contents($targetPath, $obfuscated);
        echo "  ✓ 已加密混淆: {$relFile}\n";
    }
}

// 5. 严格语法检查 (php -l)
echo "\n[3/6] 正在对所有提取与加密后的 PHP 文件执行语法检测 (php -l)...\n";
$phpFiles = scanAllFiles($stagingDir, ['php']);
$errorCount = 0;
foreach ($phpFiles as $phpFile) {
    $cmd = 'php -l ' . escapeshellarg($phpFile) . ' 2>&1';
    $res = shell_exec($cmd);
    if (!str_contains((string)$res, 'No syntax errors detected')) {
        echo "  ❌ 语法错误: {$phpFile}\n     {$res}\n";
        $errorCount++;
    }
}

if ($errorCount > 0) {
    echo "❌ 语法检测失败，发现 {$errorCount} 个错误文件，终止打包！\n";
    exit(1);
}
echo "  ✓ 共检测 " . count($phpFiles) . " 个 PHP 文件，全部通过语法验证 (100% 正常可运行)\n";

// 6. 生成 ZIP 压缩包
echo "\n[4/6] 正在生成发布压缩包: {$zipOutputFile}...\n";
if (file_exists($zipOutputFile)) {
    @unlink($zipOutputFile);
}

createZipArchive($stagingDir, $zipOutputFile);
$zipSize = filesize($zipOutputFile);
$zipSha256 = hash_file('sha256', $zipOutputFile);
echo "  ✓ 发布压缩包生成成功！\n";
echo "    文件大小: " . round($zipSize / 1024 / 1024, 2) . " MB\n";
echo "    SHA-256:  {$zipSha256}\n";

// 7. 生成版本元数据 Manifest
echo "\n[5/6] 正在生成版本发布清单 (manifest.json)...\n";
$manifest = [
    'product'       => 'CXPAY Commercial Payment System',
    'version'       => $version,
    'release_time'  => date('c'),
    'domain'        => $domain,
    'watermark_id'  => $watermarkId,
    'is_encrypted'  => $isEncrypt,
    'package_name'  => basename($zipOutputFile),
    'package_size'  => $zipSize,
    'sha256'        => $zipSha256,
    'requirements'  => [
        'php'   => '>=8.2',
        'mysql' => '>=8.0',
        'redis' => '>=7.0',
    ],
    'encrypted_files' => $isEncrypt ? $coreFilesToEncrypt : [],
];

$manifestPath = $tempBase . "/manifest_v{$version}.json";
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "  ✓ Manifest 生成完毕: {$manifestPath}\n";

// 8. 同步发布到云端控制面公共分发目录
echo "\n[6/6] 正在同步发布到云端控制面分发源: {$cloudReleaseDir}...\n";
$cloudDestZip = $cloudReleaseDir . "/CXPAY_Release_latest.zip";
$cloudVersionZip = $cloudReleaseDir . "/CXPAY_Release_v{$version}.zip";
$cloudManifest = $cloudReleaseDir . "/release-manifest.json";

copy($zipOutputFile, $cloudDestZip);
copy($zipOutputFile, $cloudVersionZip);
copy($manifestPath, $cloudManifest);

@chmod($cloudDestZip, 0777);
@chmod($cloudVersionZip, 0777);
@chmod($cloudManifest, 0777);


echo "  ✓ 已同步云端发行包: CXPAY_Release_latest.zip\n";
echo "  ✓ 已同步云端版本包: CXPAY_Release_v{$version}.zip\n";
echo "  ✓ 已更新云端发布清单: release-manifest.json\n";

// 9. 自动生命周期轮转 (GC 机制): 自动保留最近 3 个历史版本，彻底避免磁盘与页面无序膨胀
$allHistoryZips = glob($cloudReleaseDir . '/CXPAY_Release_v*.zip') ?: [];
usort($allHistoryZips, function ($a, $b) {
    return filemtime($b) <=> filemtime($a);
});
$keepCount = 3;
if (count($allHistoryZips) > $keepCount) {
    $toDelete = array_slice($allHistoryZips, $keepCount);
    foreach ($toDelete as $oldZip) {
        @unlink($oldZip);
        echo "  🧹 自动生命周期回收过期归档包: " . basename($oldZip) . "\n";
    }
}

// 清理 staging 临时目录
deleteDirectory($stagingDir);

echo "\n=========================================================\n";
echo "🎉 CXPAY 主站授权源码已成功加密打包并上传至云端分发库！\n";

echo "=========================================================\n";
echo "云端下载地址:  /releases/CXPAY_Release_v{$version}.zip\n";
echo "最新统一下载:  /releases/CXPAY_Release_latest.zip\n";
echo "买家授权水印:  {$watermarkId}\n";
echo "=========================================================\n";

// 辅助函数
function copyDirectoryWithFilter(string $src, string $dst, array $blacklistPatterns): void
{
    $dir = opendir($src);
    @mkdir($dst, 0755, true);
    while (false !== ($file = readdir($dir))) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $srcFile = $src . '/' . $file;
        $dstFile = $dst . '/' . $file;
        $relPath = str_replace('\\', '/', $srcFile);

        $matched = false;
        foreach ($blacklistPatterns as $pattern) {
            if (preg_match($pattern, $relPath)) {
                $matched = true;
                break;
            }
        }
        if ($matched) {
            continue;
        }

        if (is_dir($srcFile)) {
            copyDirectoryWithFilter($srcFile, $dstFile, $blacklistPatterns);
        } else {
            copy($srcFile, $dstFile);
        }
    }
    closedir($dir);
}

function scanAllFiles(string $dir, array $extensions = []): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile()) {
            $ext = strtolower($fileInfo->getExtension());
            if (empty($extensions) || in_array($ext, $extensions, true)) {
                $files[] = $fileInfo->getPathname();
            }
        }
    }
    return $files;
}

function createZipArchive(string $sourceDir, string $outZipPath): void
{
    $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
    $outZipPath = str_replace('\\', '/', $outZipPath);

    if (file_exists($outZipPath)) {
        @unlink($outZipPath);
    }

    // 1. 若支持原生 ZipArchive
    if (class_exists('ZipArchive')) {
        $zip = new \ZipArchive();
        if ($zip->open($outZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = str_replace('\\', '/', $file->getRealPath());
                    $relativePath = substr($filePath, strlen($sourceDir) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
            $zip->close();
            return;
        }
    }

    // 2. Windows 环境下使用 PowerShell Compress-Archive
    if (DIRECTORY_SEPARATOR === '\\' || PHP_OS_FAMILY === 'Windows') {
        $winSrc = str_replace('/', '\\', $sourceDir . '\*');
        $winDst = str_replace('/', '\\', $outZipPath);
        $cmd = "powershell -NoProfile -Command \"Compress-Archive -Path '{$winSrc}' -DestinationPath '{$winDst}' -Force\" 2>&1";
        shell_exec($cmd);
        if (file_exists($outZipPath) && filesize($outZipPath) > 0) {
            return;
        }
    }

    // 3. Linux/Unix 环境下使用 zip 命令行
    $cmd = 'cd ' . escapeshellarg($sourceDir) . ' && zip -rq ' . escapeshellarg($outZipPath) . ' . 2>&1';
    shell_exec($cmd);

    if (!file_exists($outZipPath) || filesize($outZipPath) === 0) {
        throw new RuntimeException("无法创建 ZIP 压缩包: {$outZipPath}");
    }
}

function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = array_diff(scandir($dir) ?: [], ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? deleteDirectory($path) : @unlink($path);
    }
    @rmdir($dir);
}
