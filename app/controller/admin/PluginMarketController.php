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
 * 支付插件市场与本地签名包生命周期管理。
 */
class PluginMarketController
{
    public function getMarketList(): Response
    {
        $plugins = [];
        $installed = PluginManager::installed();

        $latestVersions = [
            'cxpay.driver.alipay_face_pay'     => '3.0.0',
            'cxpay.driver.alipay_cookie_cloud' => '1.2.0',
            'cxpay.driver.wechat_dy_bill'      => '2.1.0',
            'cxpay.app_asst_universal'         => '2.0.0',
            'cxpay.driver.usdt_trc20'          => '1.5.0',
        ];

        foreach ($installed as $pluginId => $entry) {
            $manifest = is_array($entry['manifest'] ?? null) ? $entry['manifest'] : [];
            $cType = (string)($entry['c_type'] ?? $manifest['c_type'] ?? str_replace('cxpay.driver.', '', $pluginId));
            if ($cType !== '' && RemovedPaymentDrivers::contains($cType)) {
                continue;
            }

            $currentVer = (string)($entry['active_version'] ?? $manifest['version'] ?? '1.0.0');
            $latestVer = $latestVersions[$pluginId] ?? $latestVersions['cxpay.driver.' . $cType] ?? $currentVer;
            $hasUpdate = version_compare($latestVer, $currentVer, '>');

            $plugins[] = [
                'c_type' => $cType,
                'name' => (string)($entry['name'] ?? $manifest['name'] ?? $pluginId),
                'version' => $currentVer,
                'latest_version' => $latestVer,
                'has_update' => $hasUpdate,
                'author' => (string)($entry['publisher'] ?? $manifest['publisher'] ?? 'CXPAY 官方团队'),
                'type' => '官方插件',
                'source' => 'cloud_plugin',
                'plugin_id' => $pluginId,
                'installed' => true,
                'enabled' => ($entry['enabled'] ?? false) === true,
                'broken' => ($entry['broken'] ?? false) === true,
                'description' => (string)($entry['description'] ?? $manifest['description'] ?? ''),
                'permissions' => (array)($manifest['permissions'] ?? []),
            ];
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
            if (($entry['enabled'] ?? false) === true) {
                PluginManager::registry()->setEnabled($pluginId, false);
            }
            PluginManager::registry()->remove($pluginId);
            try {
                $this->lifecycle()->uninstall($pluginId);
            } catch (\Throwable) {
                // 官方驱动或无独立安装包目录的插件无需物理删除目录
            }
            PaymentManager::flush();
            return json(['code' => 1, 'msg' => '插件已成功卸载']);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '卸载失败：' . $e->getMessage()]);
        }
    }

    public function getCloudMarket(): Response
    {
        return (new CloudPluginMarketController())->getCloudMarket();
    }

    public function createPurchaseOrder(Request $request): Response
    {
        return (new CloudPluginMarketController())->createPurchaseOrder($request);
    }

    public function checkOrderStatus(Request $request): Response
    {
        return (new CloudPluginMarketController())->checkOrderStatus($request);
    }

    public function confirmPayment(Request $request): Response
    {
        return (new CloudPluginMarketController())->confirmPayment($request);
    }

    public function buyFromCloud(Request $request): Response
    {
        return (new CloudPluginMarketController())->buyFromCloud($request);
    }

    public function downloadFromCloud(Request $request): Response
    {
        return (new CloudPluginMarketController())->downloadFromCloud($request);
    }

    public function instanceStatus(): Response
    {
        return (new CloudPluginMarketController())->instanceStatus();
    }

    public function activateInstance(Request $request): Response
    {
        return (new CloudPluginMarketController())->activateInstance($request);
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
