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
        $this->mutate(function (array $registry) use ($manifest, $path): array {
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
            return $registry;
        });
    }

    /**
     * 记录官方云端插件安装
     */
    public function recordOfficialInstall(string $pluginId, array $meta): void
    {
        $this->mutate(function (array $registry) use ($pluginId, $meta): array {
            $entry = $registry[$pluginId] ?? [
                'id' => $pluginId,
                'enabled' => true,
                'versions' => [],
                'installed_at' => time(),
            ];
            $entry['id'] = $pluginId;
            $entry['name'] = (string)($meta['name'] ?? $pluginId);
            $entry['publisher'] = (string)($meta['publisher'] ?? 'cxpay.official');
            $entry['active_version'] = (string)($meta['version'] ?? '1.0.0');
            $entry['description'] = (string)($meta['description'] ?? '');
            $entry['enabled'] = true;
            $entry['c_type'] = (string)($meta['c_type'] ?? str_replace('cxpay.driver.', '', $pluginId));
            $entry['updated_at'] = time();
            $registry[$pluginId] = $entry;
            return $registry;
        });
    }

    public function setEnabled(string $pluginId, bool $enabled): void
    {
        $this->mutate(function (array $registry) use ($pluginId, $enabled): array {
            if (!isset($registry[$pluginId])) {
                throw new PluginException('插件尚未安装');
            }
            $registry[$pluginId]['enabled'] = $enabled;
            $registry[$pluginId]['updated_at'] = time();
            $registry[$pluginId]['history'] = $this->appendHistory(
                (array)($registry[$pluginId]['history'] ?? []),
                ['action' => $enabled ? 'enable' : 'disable', 'time' => time()]
            );
            return $registry;
        });
    }

    public function activateVersion(string $pluginId, string $version): void
    {
        $this->mutate(function (array $registry) use ($pluginId, $version): array {
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
            return $registry;
        });
    }

    /** @return array<string, mixed> */
    public function remove(string $pluginId): array
    {
        $removed = [];
        $this->mutate(function (array $registry) use ($pluginId, &$removed): array {
            $entry = $registry[$pluginId] ?? null;
            if (is_array($entry)) {
                $removed = $entry;
                unset($registry[$pluginId]);
            }
            return $registry;
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
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }
        $registry = $this->read();
        $res = $callback($registry);
        if (is_array($res)) {
            $registry = $res;
        }
        ksort($registry);
        $json = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($this->file, $json, LOCK_EX);
        @chmod($this->file, 0666);
    }

    /** @param list<array<string, mixed>> $history @param array<string, mixed> $event */
    private function appendHistory(array $history, array $event): array
    {
        $history[] = $event;
        return array_slice($history, -100);
    }
}
