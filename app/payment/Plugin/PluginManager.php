<?php

declare(strict_types=1);

namespace app\payment\Plugin;

use app\payment\PaymentManager;

final class PluginManager
{
    private static ?PluginRegistry $registry = null;

    public static function registry(): PluginRegistry
    {
        return self::$registry ??= new PluginRegistry((string)config(
            'payment_plugin.registry',
            base_path() . '/runtime/payment_plugins/registry.json'
        ));
    }

    public static function useRegistry(?PluginRegistry $registry): void
    {
        self::$registry = $registry;
    }

    public static function isEnabled(string $pluginId): bool
    {
        return self::registry()->isEnabled($pluginId);
    }

    /** @return array<string, array<string, mixed>> */
    public static function installed(): array
    {
        $result = [];
        foreach (self::registry()->all() as $pluginId => $entry) {
            try {
                [$manifest] = self::activeManifest($entry);
                $result[$pluginId] = $entry + ['manifest' => $manifest->toArray()];
            } catch (\Throwable $e) {
                $result[$pluginId] = $entry + ['broken' => true, 'error' => $e->getMessage()];
            }
        }
        return $result;
    }

    public static function discoverEnabledDrivers(): void
    {
        foreach (self::registry()->all() as $pluginId => $entry) {
            if (($entry['enabled'] ?? false) !== true) {
                continue;
            }
            try {
                [$manifest, $directory] = self::activeManifest($entry);
                foreach ($manifest->drivers() as $driver) {
                    $entryFile = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $driver['file']);
                    self::assertInsideDirectory($entryFile, $directory);
                    if (!is_file($entryFile)) {
                        throw new PluginException("驱动入口不存在: {$driver['file']}");
                    }
                    require_once $entryFile;
                    $class = (string)$driver['class'];
                    $instance = new $class();
                    (new CloudConnectorPolicy())->assertDriverMeta($manifest, $instance->getMeta());
                    PaymentManager::registerPluginDriver($driver['code'], $class, $pluginId);
                }
            } catch (\Throwable) {
                // 单个损坏插件不能阻断其他支付方式；详情页会展示 broken 状态。
            }
        }
    }

    /** @param array<string, mixed> $entry @return array{PluginManifest,string} */
    private static function activeManifest(array $entry): array
    {
        $version = (string)($entry['active_version'] ?? '');
        $directory = (string)($entry['versions'][$version]['path'] ?? '');
        if ($directory === '' || !is_dir($directory)) {
            throw new PluginException('插件活动版本目录不存在');
        }
        $pluginRoot = realpath((string)config('payment_plugin.path', base_path() . '/plugin/cxpay'));
        $resolvedDirectory = realpath($directory);
        if ($pluginRoot === false || $resolvedDirectory === false
            || !str_starts_with($resolvedDirectory, $pluginRoot . DIRECTORY_SEPARATOR)) {
            throw new PluginException('插件活动版本目录越过了支付插件根目录');
        }
        $directory = $resolvedDirectory;
        $manifestFile = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifestFile)) {
            throw new PluginException('插件清单不存在');
        }
        $json = file_get_contents($manifestFile);
        if ($json === false) {
            throw new PluginException('插件清单不可读');
        }
        return [PluginManifest::fromJson($json), $directory];
    }

    private static function assertInsideDirectory(string $path, string $directory): void
    {
        $root = realpath($directory);
        $resolved = realpath($path);
        if ($root === false || $resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            throw new PluginException('插件入口越过了插件目录边界');
        }
    }
}
