<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Channel;
use app\payment\PaymentManager;
use app\payment\RemovedPaymentDrivers;
use app\payment\Plugin\PluginException;
use app\payment\Plugin\PluginLifecycleManager;
use app\payment\Plugin\PluginManager;
use app\payment\Plugin\PluginPackageInstaller;
use support\Request;
use support\Response;

/**
 * 支付插件市场与本地签名包安装。
 */
class PluginMarketController
{
    public function getMarketList(): Response
    {
        $plugins = [];
        foreach (PaymentManager::getRegisteredDrivers() as $cType => $meta) {
            $plugins[] = [
                'c_type' => $cType,
                'name' => (string)($meta['title'] ?? $cType),
                'version' => (string)($meta['version'] ?? '内置'),
                'author' => (string)($meta['author'] ?? '本地代码库'),
                'type' => '支付驱动',
                'source' => 'builtin',
                'plugin_id' => null,
                'installed' => true,
                'enabled' => true,
                'description' => (string)($meta['description'] ?? ''),
            ];
        }

        foreach (PluginManager::installed() as $pluginId => $entry) {
            $manifest = is_array($entry['manifest'] ?? null) ? $entry['manifest'] : [];
            $drivers = is_array($manifest['drivers'] ?? null) ? $manifest['drivers'] : [];
            if ($drivers === []) {
                $drivers = [['code' => '']];
            }
            foreach ($drivers as $driver) {
                $cType = trim((string)($driver['code'] ?? ''));
                if ($cType !== '' && RemovedPaymentDrivers::contains($cType)) {
                    continue;
                }

                $plugins[] = [
                    'c_type' => $cType,
                    'name' => (string)($manifest['name'] ?? $entry['name'] ?? $pluginId),
                    'version' => (string)($entry['active_version'] ?? ''),
                    'author' => (string)($entry['publisher'] ?? ''),
                    'type' => '支付插件',
                    'source' => 'plugin',
                    'plugin_id' => $pluginId,
                    'installed' => true,
                    'enabled' => ($entry['enabled'] ?? false) === true,
                    'broken' => ($entry['broken'] ?? false) === true,
                    'description' => (string)($manifest['description'] ?? $entry['error'] ?? ''),
                    'permissions' => (array)($manifest['permissions'] ?? []),
                    'versions' => array_keys((array)($entry['versions'] ?? [])),
                ];
            }
        }

        return json([
            'code' => 1,
            'msg' => '获取成功',
            'data' => [
                'list' => $plugins,
                'total' => count($plugins),
                'installed' => count($plugins),
            ],
        ]);
    }

    public function installPlugin(Request $request): Response
    {
        $package = $request->file('package');
        if (!$package || is_array($package) || !$package->isValid()) {
            return json(['code' => -1, 'msg' => '请选择有效的插件安装包']);
        }
        if (!str_ends_with(strtolower((string)$package->getUploadName()), '.cxpay-plugin')) {
            return json(['code' => -1, 'msg' => '只允许上传 .cxpay-plugin 安装包']);
        }

        try {
            $installer = new PluginPackageInstaller(
                (string)config('payment_plugin.path', base_path() . '/plugin/cxpay'),
                (string)config('payment_plugin.trusted_keys', base_path() . '/config/plugin_keys'),
                PluginManager::registry(),
                (int)config('payment_plugin.max_package_size', 20 * 1024 * 1024),
                (int)config('payment_plugin.max_file_size', 5 * 1024 * 1024),
                (int)config('payment_plugin.max_files', 500),
            );
            $plugin = $installer->install($package->getPathname());
            PaymentManager::flush();
            return json(['code' => 1, 'msg' => '插件安装成功，完成配置并检查权限后可手动启用', 'data' => $plugin]);
        } catch (PluginException $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        } catch (\Throwable) {
            return json(['code' => -1, 'msg' => '插件安装失败，请检查服务端日志'])->withStatus(500);
        }
    }

    public function setEnabled(Request $request): Response
    {
        $pluginId = trim((string)$request->post('plugin_id'));
        $enabled = (int)$request->post('enabled', 0) === 1;
        if (!preg_match('/^cxpay\.[a-z0-9][a-z0-9._-]{2,80}$/', $pluginId)) {
            return json(['code' => -1, 'msg' => '插件 ID 不合法']);
        }

        $entry = PluginManager::installed()[$pluginId] ?? null;
        if (!$entry) {
            return json(['code' => -1, 'msg' => '插件尚未安装']);
        }
        $drivers = (array)($entry['manifest']['drivers'] ?? []);
        $driverCodes = array_values(array_filter(array_map(
            static fn(array $driver): string => (string)($driver['code'] ?? ''),
            $drivers
        )));

        if (!$enabled && $driverCodes !== []) {
            $activeChannels = Channel::whereIn('c_type', $driverCodes)->where('status', 1)->count();
            if ($activeChannels > 0) {
                return json(['code' => -1, 'msg' => '该插件仍有启用中的支付通道，请先停用通道']);
            }
        }

        try {
            PluginManager::registry()->setEnabled($pluginId, $enabled);
            PaymentManager::flush();
            if ($enabled) {
                foreach ($driverCodes as $driverCode) {
                    PaymentManager::make($driverCode);
                    if (PaymentManager::pluginId($driverCode) !== $pluginId) {
                        throw new PluginException("驱动标识与已有通道冲突: {$driverCode}");
                    }
                }
            }
            return json(['code' => 1, 'msg' => $enabled ? '插件已启用' : '插件已停用']);
        } catch (\Throwable $e) {
            if ($enabled) {
                PluginManager::registry()->setEnabled($pluginId, false);
                PaymentManager::flush();
            }
            return json(['code' => -1, 'msg' => '插件启停检查失败：' . $e->getMessage()]);
        }
    }

    public function rollback(Request $request): Response
    {
        $pluginId = trim((string)$request->post('plugin_id'));
        $version = trim((string)$request->post('version'));
        if (!$this->validPluginId($pluginId)) {
            return json(['code' => -1, 'msg' => '插件 ID 不合法']);
        }
        try {
            $result = $this->lifecycle()->rollback($pluginId, $version);
            PaymentManager::flush();
            return json(['code' => 1, 'msg' => "插件已回滚到 {$version}，请检查配置后重新启用", 'data' => $result]);
        } catch (PluginException $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function uninstall(Request $request): Response
    {
        $pluginId = trim((string)$request->post('plugin_id'));
        if (!$this->validPluginId($pluginId)) {
            return json(['code' => -1, 'msg' => '插件 ID 不合法']);
        }
        $entry = PluginManager::installed()[$pluginId] ?? null;
        if (!$entry) {
            return json(['code' => -1, 'msg' => '插件尚未安装']);
        }
        $driverCodes = array_values(array_filter(array_map(
            static fn(array $driver): string => (string)($driver['code'] ?? ''),
            (array)($entry['manifest']['drivers'] ?? [])
        )));
        if ($driverCodes !== [] && Channel::whereIn('c_type', $driverCodes)->exists()) {
            return json(['code' => -1, 'msg' => '仍有支付通道引用该插件，请先删除或迁移这些通道配置']);
        }
        try {
            $this->lifecycle()->uninstall($pluginId);
            PaymentManager::flush();
            return json(['code' => 1, 'msg' => '插件已卸载，历史订单和审计数据未删除']);
        } catch (PluginException $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    private function lifecycle(): PluginLifecycleManager
    {
        return new PluginLifecycleManager(
            (string)config('payment_plugin.path', base_path() . '/plugin/cxpay'),
            PluginManager::registry(),
        );
    }

    private function validPluginId(string $pluginId): bool
    {
        return preg_match('/^cxpay\.[a-z0-9][a-z0-9._-]{2,80}$/', $pluginId) === 1;
    }
}
