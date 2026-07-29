<?php

declare(strict_types=1);

namespace app\payment\Plugin;

final class PluginRegistry
{
    public function __construct(private readonly string $file)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->read();
    }

    /** @return array<string, mixed>|null */
    public function get(string $pluginId): ?array
    {
        return $this->read()[$pluginId] ?? null;
    }

    public function isEnabled(string $pluginId): bool
    {
        return ($this->get($pluginId)['enabled'] ?? false) === true;
    }

    public function recordInstall(PluginManifest $manifest, string $path): void
    {
        $this->mutate(function (array &$registry) use ($manifest, $path): void {
            $entry = $registry[$manifest->id()] ?? [
                'id' => $manifest->id(),
                'enabled' => false,
                'versions' => [],
                'installed_at' => time(),
            ];
            $entry['name'] = $manifest->name();
            $entry['publisher'] = $manifest->publisher();
            $entry['active_version'] = $manifest->version();
            $entry['enabled'] = false;
            $entry['updated_at'] = time();
            $entry['versions'][$manifest->version()] = [
                'path' => $path,
                'installed_at' => time(),
            ];
            $entry['history'] = $this->appendHistory((array)($entry['history'] ?? []), [
                'action' => count($entry['versions']) > 1 ? 'upgrade' : 'install',
                'version' => $manifest->version(),
                'time' => time(),
            ]);
            $registry[$manifest->id()] = $entry;
        });
    }

    public function setEnabled(string $pluginId, bool $enabled): void
    {
        $this->mutate(function (array &$registry) use ($pluginId, $enabled): void {
            if (!isset($registry[$pluginId])) {
                throw new PluginException('插件尚未安装');
            }
            $registry[$pluginId]['enabled'] = $enabled;
            $registry[$pluginId]['updated_at'] = time();
            $registry[$pluginId]['history'] = $this->appendHistory(
                (array)($registry[$pluginId]['history'] ?? []),
                ['action' => $enabled ? 'enable' : 'disable', 'time' => time()]
            );
        });
    }

    public function activateVersion(string $pluginId, string $version): void
    {
        $this->mutate(function (array &$registry) use ($pluginId, $version): void {
            $entry = $registry[$pluginId] ?? null;
            if (!is_array($entry)) {
                throw new PluginException('插件尚未安装');
            }
            if (($entry['enabled'] ?? false) === true) {
                throw new PluginException('回滚前必须先停用插件');
            }
            if (!isset($entry['versions'][$version])) {
                throw new PluginException('目标插件版本不存在');
            }
            $fromVersion = (string)($entry['active_version'] ?? '');
            $registry[$pluginId]['active_version'] = $version;
            $registry[$pluginId]['updated_at'] = time();
            $registry[$pluginId]['history'] = $this->appendHistory(
                (array)($entry['history'] ?? []),
                ['action' => 'rollback', 'from' => $fromVersion, 'version' => $version, 'time' => time()]
            );
        });
    }

    /** @return array<string, mixed> */
    public function remove(string $pluginId): array
    {
        $removed = [];
        $this->mutate(function (array &$registry) use ($pluginId, &$removed): void {
            $entry = $registry[$pluginId] ?? null;
            if (!is_array($entry)) {
                throw new PluginException('插件尚未安装');
            }
            if (($entry['enabled'] ?? false) === true) {
                throw new PluginException('卸载前必须先停用插件');
            }
            $removed = $entry;
            unset($registry[$pluginId]);
        });
        return $removed;
    }

    /** @return array<string, array<string, mixed>> */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $json = file_get_contents($this->file);
        if ($json === false || trim($json) === '') {
            return [];
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PluginException('插件注册表已损坏', 0, $e);
        }
        if (!is_array($data)) {
            throw new PluginException('插件注册表格式错误');
        }
        return $data;
    }

    private function mutate(callable $callback): void
    {
        $directory = dirname($this->file);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new PluginException('无法创建插件注册表目录');
        }
        $lock = fopen($this->file . '.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new PluginException('无法锁定插件注册表');
        }
        try {
            $registry = $this->read();
            $callback($registry);
            ksort($registry);
            $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $temporary = $this->file . '.tmp.' . bin2hex(random_bytes(6));
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $this->file)) {
                @unlink($temporary);
                throw new PluginException('写入插件注册表失败');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param list<array<string, mixed>> $history @param array<string, mixed> $event */
    private function appendHistory(array $history, array $event): array
    {
        $history[] = $event;
        return array_slice($history, -100);
    }
}
