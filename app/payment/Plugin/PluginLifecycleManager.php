<?php

declare(strict_types=1);

namespace app\payment\Plugin;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PluginLifecycleManager
{
    public function __construct(
        private readonly string $pluginRoot,
        private readonly PluginRegistry $registry,
    ) {
    }

    /** @return array<string, mixed> */
    public function rollback(string $pluginId, string $version): array
    {
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/', $version)) {
            throw new PluginException('回滚版本号不合法');
        }
        $entry = $this->registry->get($pluginId);
        if (!$entry) {
            throw new PluginException('插件尚未安装');
        }
        if (($entry['enabled'] ?? false) === true) {
            throw new PluginException('回滚前必须先停用插件');
        }
        $directory = (string)($entry['versions'][$version]['path'] ?? '');
        $directory = $this->validatedVersionDirectory($directory);
        $manifestFile = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
        $json = is_file($manifestFile) ? file_get_contents($manifestFile) : false;
        if ($json === false) {
            throw new PluginException('目标版本插件清单不存在');
        }
        $manifest = PluginManifest::fromJson($json);
        if (!hash_equals($pluginId, $manifest->id()) || !hash_equals($version, $manifest->version())) {
            throw new PluginException('目标版本清单与注册表不一致');
        }
        $this->registry->activateVersion($pluginId, $version);
        return ['id' => $pluginId, 'version' => $version, 'enabled' => false];
    }

    public function uninstall(string $pluginId): void
    {
        $entry = $this->registry->get($pluginId);
        if (!$entry) {
            throw new PluginException('插件尚未安装');
        }
        if (($entry['enabled'] ?? false) === true) {
            throw new PluginException('卸载前必须先停用插件');
        }

        $directories = [];
        foreach ((array)($entry['versions'] ?? []) as $version) {
            $directories[] = $this->validatedVersionDirectory((string)($version['path'] ?? ''));
        }
        // 先移除运行注册，即使后续文件清理失败，残留代码也不会再次加载。
        $this->registry->remove($pluginId);
        foreach (array_unique($directories) as $directory) {
            $this->removeDirectory($directory);
            $parent = dirname($directory);
            if ($this->isInsideRoot($parent) && is_dir($parent) && $this->isDirectoryEmpty($parent)) {
                @rmdir($parent);
            }
        }
    }

    private function validatedVersionDirectory(string $directory): string
    {
        $resolved = realpath($directory);
        if ($resolved === false || !$this->isInsideRoot($resolved)) {
            throw new PluginException('插件版本目录越过了支付插件根目录');
        }
        return $resolved;
    }

    private function isInsideRoot(string $path): bool
    {
        $root = realpath($this->pluginRoot);
        $resolved = realpath($path);
        return $root !== false && $resolved !== false
            && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR);
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new PluginException('插件目录包含不允许的符号链接，已停止清理');
            }
            $ok = $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            if (!$ok) {
                throw new PluginException('插件文件清理失败，请检查目录权限');
            }
        }
        if (!rmdir($directory)) {
            throw new PluginException('插件版本目录清理失败');
        }
    }

    private function isDirectoryEmpty(string $directory): bool
    {
        $items = scandir($directory);
        return $items !== false && count($items) === 2;
    }
}
