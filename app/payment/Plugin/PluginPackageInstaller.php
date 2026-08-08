<?php

declare(strict_types=1);

namespace app\payment\Plugin;

use app\payment\RemovedPaymentDrivers;
use PharData;
use RecursiveIteratorIterator;

final class PluginPackageInstaller
{
    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = [
        'php', 'json', 'md', 'txt', 'html', 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'sql',
    ];

    public function __construct(
        private readonly string $pluginRoot,
        private readonly string $trustedKeysDirectory,
        private readonly PluginRegistry $registry,
        private readonly int $maxPackageSize = 20971520,
        private readonly int $maxFileSize = 5242880,
        private readonly int $maxFiles = 500,
    ) {
    }

    /** @return array<string, mixed> */
    public function install(string $packageFile): array
    {
        if (!is_file($packageFile) || !is_readable($packageFile)) {
            throw new PluginException('插件安装包不存在或不可读');
        }
        $packageSize = filesize($packageFile);
        if ($packageSize === false || $packageSize <= 0 || $packageSize > $this->maxPackageSize) {
            throw new PluginException('插件安装包大小不合法');
        }
        $magic = file_get_contents($packageFile, false, null, 0, 4);
        if ($magic === false || !str_starts_with($magic, "PK\x03\x04")) {
            throw new PluginException('插件安装包必须是 ZIP 格式');
        }

        $archiveFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'cxpay-plugin-' . bin2hex(random_bytes(12)) . '.zip';
        if (!copy($packageFile, $archiveFile)) {
            throw new PluginException('无法创建插件包校验副本');
        }
        try {
            $archive = $this->openArchive($archiveFile);
            $files = $this->readArchiveFiles($archive, $archiveFile);
            unset($archive);
        } finally {
            @unlink($archiveFile);
        }
        if (!isset($files['manifest.json'], $files['signature.json'])) {
            throw new PluginException('插件包缺少 manifest.json 或 signature.json');
        }

        $manifest = PluginManifest::fromJson($files['manifest.json']);
        $this->verifySignature($manifest, $files['signature.json'], $files);

        // 云端授权联网二次校验（当代理站点已配置 site_domain 与 auth_key 时自动触发）
        $domain  = function_exists('config') ? strtolower(trim((string)config('app.site_domain', ''))) : '';
        $authKey = function_exists('config') ? trim((string)config('app.auth_key', '')) : '';
        $cloudUrl = function_exists('config') ? trim((string)config('app.cloud_url', '')) : '';

        if ($domain !== '' && $authKey !== '' && $cloudUrl !== '') {
            try {
                $query = http_build_query([
                    'domain'    => $domain,
                    'auth_key'  => $authKey,
                    'plugin_id' => $manifest->id(),
                ]);
                $url = rtrim($cloudUrl, '/') . '/api/cloud/plugin/market_list?' . $query;
                $ctx = stream_context_create([
                    'http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true],
                    'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $raw = @file_get_contents($url, false, $ctx);
                if ($raw !== false && $raw[0] === '{') {
                    $json = json_decode($raw, true);
                    if (is_array($json) && (int)($json['code'] ?? 0) === 1 && is_array($json['data'] ?? null)) {
                        $match = null;
                        foreach ($json['data'] as $item) {
                            if (($item['plugin_id'] ?? '') === $manifest->id()) {
                                $match = $item;
                                break;
                            }
                        }
                        if ($match && !($match['license_valid'] ?? false)) {
                            throw new PluginException('当前站点尚未获得该插件的有效授权（' . ($match['expire_label'] ?? '未购买') . '），请在插件商城购买授权');
                        }
                    }
                }
            } catch (PluginException $pe) {
                throw $pe;
            } catch (\Throwable) {
                // 允许离线或开发调试环境，忽略请求异常
            }
        }

        foreach ($manifest->drivers() as $driver) {
            $code = trim((string)($driver['code'] ?? ''));
            if (RemovedPaymentDrivers::contains($code)) {
                throw new PluginException(
                    "插件声明了已永久移除的支付驱动: {$code}"
                );
            }
        }

        $this->assertDriverFilesDeclared($manifest, $files);

        $target = $this->versionDirectory($manifest);
        if (file_exists($target)) {
            throw new PluginException('该插件版本已经安装');
        }
        $staging = $target . '.staging.' . bin2hex(random_bytes(6));
        $this->writeStagingFiles($staging, $files);

        try {
            $this->verifyStagedDrivers($manifest, $staging);
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
                throw new PluginException('无法创建插件版本目录');
            }
            if (!rename($staging, $target)) {
                throw new PluginException('无法启用插件版本目录');
            }
            $this->registry->recordInstall($manifest, $target);
        } catch (\Throwable $e) {
            $this->removeDirectory($staging);
            if (is_dir($target) && $this->registry->get($manifest->id()) === null) {
                $this->removeDirectory($target);
            }
            throw $e;
        }

        return [
            'id' => $manifest->id(),
            'name' => $manifest->name(),
            'version' => $manifest->version(),
            'publisher' => $manifest->publisher(),
            'enabled' => false,
        ];
    }

    private function openArchive(string $packageFile): PharData
    {
        try {
            return new PharData($packageFile, 0, null, \Phar::ZIP);
        } catch (\Throwable $e) {
            throw new PluginException('无法读取插件 ZIP 安装包', 0, $e);
        }
    }

    /** @return array<string, string> */
    private function readArchiveFiles(PharData $archive, string $packageFile): array
    {
        $files = [];
        $archiveMarker = '/' . basename($packageFile) . '/';
        $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($iterator as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            $pathName = str_replace('\\', '/', $entry->getPathname());
            $markerPosition = stripos($pathName, $archiveMarker);
            if ($markerPosition === false) {
                throw new PluginException('无法解析插件包内部路径');
            }
            $relative = substr($pathName, $markerPosition + strlen($archiveMarker));
            $relative = PluginManifest::normalizeRelativePath($relative);
            if (isset($files[$relative])) {
                throw new PluginException("插件包包含重复文件: {$relative}");
            }
            if (count($files) >= $this->maxFiles || $entry->getSize() > $this->maxFileSize) {
                throw new PluginException('插件包文件数量或单文件大小超出限制');
            }
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                throw new PluginException("插件包包含禁止的文件类型: {$relative}");
            }
            if (str_starts_with(strtolower($relative), 'vendor/')) {
                throw new PluginException('插件包不得携带 vendor 依赖目录');
            }
            $content = file_get_contents($entry->getPathname());
            if ($content === false) {
                throw new PluginException("无法读取插件文件: {$relative}");
            }
            $files[$relative] = $content;
        }
        ksort($files);
        return $files;
    }

    /** @param array<string, string> $files */
    private function verifySignature(PluginManifest $manifest, string $signatureJson, array $files): void
    {
        try {
            $signature = json_decode($signatureJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PluginException('插件签名文件不是合法 JSON', 0, $e);
        }
        if (!is_array($signature)
            || ($signature['algorithm'] ?? '') !== 'rsa-sha256'
            || ($signature['publisher'] ?? '') !== $manifest->publisher()
            || !is_array($signature['files'] ?? null)
            || !is_string($signature['signature'] ?? null)) {
            throw new PluginException('插件签名声明不合法');
        }

        $expectedHashes = $signature['files'];
        ksort($expectedHashes);
        $actualHashes = [];
        foreach ($files as $path => $content) {
            if ($path !== 'signature.json') {
                $actualHashes[$path] = hash('sha256', $content);
            }
        }
        if ($expectedHashes !== $actualHashes) {
            $mismatched = array_values(array_unique(array_merge(
                array_diff(array_keys($expectedHashes), array_keys($actualHashes)),
                array_diff(array_keys($actualHashes), array_keys($expectedHashes)),
                array_keys(array_filter($actualHashes, static fn(string $hash, string $path): bool =>
                    !isset($expectedHashes[$path]) || !hash_equals((string)$expectedHashes[$path], $hash), ARRAY_FILTER_USE_BOTH))
            )));
            throw new PluginException('插件文件清单或哈希校验失败: ' . implode(', ', $mismatched));
        }

        $publisher = $manifest->publisher();
        $publicKeyFile = rtrim($this->trustedKeysDirectory, '/\\') . DIRECTORY_SEPARATOR . $publisher . '.pem';
        if (!is_file($publicKeyFile) || !is_readable($publicKeyFile)) {
            throw new PluginException("发布者 {$publisher} 尚未加入信任列表");
        }
        $publicKey = openssl_pkey_get_public((string)file_get_contents($publicKeyFile));
        $decodedSignature = base64_decode($signature['signature'], true);
        if ($publicKey === false || $decodedSignature === false) {
            throw new PluginException('插件公钥或签名编码不合法');
        }
        $payload = self::canonicalJson([
            'algorithm' => 'rsa-sha256',
            'publisher' => $publisher,
            'files' => $expectedHashes,
        ]);
        if (openssl_verify($payload, $decodedSignature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new PluginException('插件发布者签名验证失败');
        }
    }

    /** @param array<string, string> $files */
    private function assertDriverFilesDeclared(PluginManifest $manifest, array $files): void
    {
        foreach ($manifest->drivers() as $driver) {
            if (!isset($files[$driver['file']])) {
                throw new PluginException("插件缺少驱动入口: {$driver['file']}");
            }
        }
    }

    /** @param array<string, string> $files */
    private function writeStagingFiles(string $staging, array $files): void
    {
        if (!mkdir($staging, 0750, true) && !is_dir($staging)) {
            throw new PluginException('无法创建插件暂存目录');
        }
        try {
            foreach ($files as $relative => $content) {
                $destination = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                    throw new PluginException("无法创建插件子目录: {$relative}");
                }
                if (file_put_contents($destination, $content, LOCK_EX) === false) {
                    throw new PluginException("无法写入插件文件: {$relative}");
                }
                @chmod($destination, str_ends_with($relative, '.php') ? 0640 : 0644);
            }
        } catch (\Throwable $e) {
            $this->removeDirectory($staging);
            throw $e;
        }
    }

    private function verifyStagedDrivers(PluginManifest $manifest, string $staging): void
    {
        foreach ($manifest->drivers() as $driver) {
            $file = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $driver['file']);
            $content = file_get_contents($file);
            if ($content === false || !str_contains($content, '<?php')) {
                throw new PluginException("驱动入口不是 PHP 源文件: {$driver['file']}");
            }
        }
    }

    private function versionDirectory(PluginManifest $manifest): string
    {
        $root = rtrim($this->pluginRoot, '/\\');
        return $root . DIRECTORY_SEPARATOR . $manifest->slug() . DIRECTORY_SEPARATOR . $manifest->version();
    }

    /** @param array<string, mixed> $value */
    public static function canonicalJson(array $value): string
    {
        $sort = static function (&$item) use (&$sort): void {
            if (!is_array($item)) {
                return;
            }
            foreach ($item as &$child) {
                $sort($child);
            }
            unset($child);
            if (!array_is_list($item)) {
                ksort($item);
            }
        };
        $sort($value);
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
