<?php

declare(strict_types=1);

use app\payment\Plugin\PluginManifest;
use app\payment\Plugin\PluginPackageInstaller;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if ($argc !== 4) {
    fwrite(STDERR, "用法: php tools/plugin/build.php <插件源码目录> <RSA私钥PEM> <输出.cxpay-plugin>\n");
    exit(2);
}

[$script, $sourceArgument, $privateKeyArgument, $outputArgument] = $argv;
$source = realpath($sourceArgument);
$privateKeyFile = realpath($privateKeyArgument);
$output = str_replace('\\', '/', $outputArgument);

if ($source === false || !is_dir($source)) {
    fwrite(STDERR, "插件源码目录不存在\n");
    exit(2);
}
if ($privateKeyFile === false || !is_file($privateKeyFile)) {
    fwrite(STDERR, "RSA 私钥文件不存在\n");
    exit(2);
}
if (!str_ends_with(strtolower($output), '.cxpay-plugin') || file_exists($output)) {
    fwrite(STDERR, "输出文件必须以 .cxpay-plugin 结尾且不能已经存在\n");
    exit(2);
}

$manifestFile = $source . DIRECTORY_SEPARATOR . 'manifest.json';
$manifestJson = is_file($manifestFile) ? file_get_contents($manifestFile) : false;
if ($manifestJson === false) {
    fwrite(STDERR, "插件源码目录缺少 manifest.json\n");
    exit(2);
}

try {
    $manifest = PluginManifest::fromJson($manifestJson);
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
        $relative = PluginManifest::normalizeRelativePath($relative);
        if ($relative === 'signature.json' || str_starts_with(strtolower($relative), 'vendor/')) {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            throw new RuntimeException("无法读取 {$relative}");
        }
        $files[$relative] = $content;
    }
    foreach ($manifest->drivers() as $driver) {
        if (!isset($files[$driver['file']])) {
            throw new RuntimeException("缺少驱动入口 {$driver['file']}");
        }
    }
    ksort($files);
    $hashes = [];
    foreach ($files as $relative => $content) {
        $hashes[$relative] = hash('sha256', $content);
    }
    $payload = PluginPackageInstaller::canonicalJson([
        'algorithm' => 'rsa-sha256',
        'publisher' => $manifest->publisher(),
        'files' => $hashes,
    ]);
    $passphrase = getenv('CXPAY_PLUGIN_KEY_PASSPHRASE');
    $privateKey = openssl_pkey_get_private(
        (string)file_get_contents($privateKeyFile),
        $passphrase === false ? null : $passphrase
    );
    if ($privateKey === false || !openssl_sign($payload, $rawSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('无法使用指定私钥完成签名');
    }
    $signature = json_encode([
        'algorithm' => 'rsa-sha256',
        'publisher' => $manifest->publisher(),
        'files' => $hashes,
        'signature' => base64_encode($rawSignature),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $outputDirectory = dirname($output);
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0750, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('无法创建输出目录');
    }
    $temporaryZip = $output . '.building.zip';
    $archive = new PharData($temporaryZip, 0, null, Phar::ZIP);
    foreach ($files as $relative => $content) {
        $archive->addFromString($relative, $content);
    }
    $archive->addFromString('signature.json', $signature);
    unset($archive);
    if (!rename($temporaryZip, $output)) {
        @unlink($temporaryZip);
        throw new RuntimeException('无法生成最终插件包');
    }
    fwrite(STDOUT, "插件包构建成功: {$output}\n");
} catch (Throwable $e) {
    if (isset($temporaryZip)) {
        @unlink($temporaryZip);
    }
    fwrite(STDERR, "构建失败: {$e->getMessage()}\n");
    exit(1);
}
