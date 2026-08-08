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
use app\service\PluginLicenseService;
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

    // ─────────────────────────────────────────────────────────────────
    // Phase 3：代理端插件商城 × 云端授权联动
    // ─────────────────────────────────────────────────────────────────

    /**
     * 拉取官方云端可购买的插件商品列表。
     * GET /api/admin/plugin/cloud_market
     *
     * 云端 API：GET {cloud_url}/api/cloud/plugin/market_list?domain=xxx&auth_key=xxx
     */
    public function getCloudMarket(Request $request): Response
    {
        [$domain, $authKey, $cloudUrl, $errResp] = $this->siteAuthParams();
        if ($errResp) {
            return $errResp;
        }

        // 调用官方云端接口拉取插件商品列表
        try {
            $url  = rtrim($cloudUrl, '/') . '/api/cloud/plugin/market_list';
            $resp = $this->cloudGet($url, ['domain' => $domain, 'auth_key' => $authKey]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '连接官方云端失败：' . $e->getMessage()]);
        }

        if ((int)($resp['code'] ?? 0) !== 1) {
            return json(['code' => -1, 'msg' => $resp['msg'] ?? '云端返回错误']);
        }

        return json(['code' => 1, 'data' => $resp['data'] ?? []]);
    }

    /**
     * 向官方云端购买指定插件的授权。
     * POST /api/admin/plugin/cloud_buy
     *
     * 参数：plugin_id, pkg_type（month/quarter/year/forever）
     */
    public function buyFromCloud(Request $request): Response
    {
        [$domain, $authKey, $cloudUrl, $errResp] = $this->siteAuthParams();
        if ($errResp) {
            return $errResp;
        }

        $pluginId = trim((string)$request->post('plugin_id'));
        $pkgType  = trim((string)$request->post('pkg_type', 'month'));

        if (!$this->validPluginId($pluginId)) {
            return json(['code' => -1, 'msg' => '插件 ID 不合法']);
        }
        if (!in_array($pkgType, ['month', 'quarter', 'year', 'forever'], true)) {
            return json(['code' => -1, 'msg' => '套期类型不合法']);
        }

        try {
            $url  = rtrim($cloudUrl, '/') . '/api/cloud/plugin/buy';
            $resp = $this->cloudPost($url, [
                'domain'    => $domain,
                'auth_key'  => $authKey,
                'plugin_id' => $pluginId,
                'pkg_type'  => $pkgType,
            ]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '连接官方云端失败：' . $e->getMessage()]);
        }

        if ((int)($resp['code'] ?? 0) !== 1) {
            return json(['code' => -1, 'msg' => $resp['msg'] ?? '购买失败，请联系官方客服']);
        }

        return json(['code' => 1, 'msg' => '购买成功', 'data' => $resp['data'] ?? []]);
    }

    /**
     * 从官方云端下载已授权插件包并在本地完成安装。
     * POST /api/admin/plugin/cloud_download
     *
     * 参数：plugin_id
     * 流程：云端验证授权 → 返回签名包二进制 → 本地 PluginPackageInstaller.install()
     */
    public function downloadFromCloud(Request $request): Response
    {
        [$domain, $authKey, $cloudUrl, $errResp] = $this->siteAuthParams();
        if ($errResp) {
            return $errResp;
        }

        $pluginId = trim((string)$request->post('plugin_id'));
        if (!$this->validPluginId($pluginId)) {
            return json(['code' => -1, 'msg' => '插件 ID 不合法']);
        }

        // 1. 向云端请求下载（云端会验证域名 + auth_key + 插件授权）
        $url = rtrim($cloudUrl, '/') . '/api/cloud/plugin/download';
        $queryStr = http_build_query([
            'domain'    => $domain,
            'auth_key'  => $authKey,
            'plugin_id' => $pluginId,
        ]);

        try {
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'timeout' => 60,
                    'header'  => "User-Agent: CXPAY-Agent/1.0\r\nAccept: application/octet-stream\r\n",
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $raw = @file_get_contents("{$url}?{$queryStr}", false, $ctx);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '下载插件包失败：' . $e->getMessage()]);
        }

        if ($raw === false || strlen($raw) < 4) {
            return json(['code' => -1, 'msg' => '插件包下载失败或响应为空，请检查授权状态']);
        }

        // 检查是否是 JSON 错误响应（云端拒绝时返回 JSON）
        if ($raw[0] === '{') {
            $errData = json_decode($raw, true);
            if (is_array($errData) && (int)($errData['code'] ?? 0) !== 1) {
                return json(['code' => -1, 'msg' => $errData['msg'] ?? '云端拒绝下载请求']);
            }
        }

        // 2. 写入临时文件
        $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'cxpay-cloud-' . bin2hex(random_bytes(8)) . '.cxpay-plugin';
        if (file_put_contents($tmpFile, $raw) === false) {
            return json(['code' => -1, 'msg' => '无法写入临时插件文件']);
        }

        // 3. 本地安装（签名校验由 PluginPackageInstaller 负责）
        try {
            $installer = new PluginPackageInstaller(
                (string)config('payment_plugin.path', base_path() . '/plugin/cxpay'),
                (string)config('payment_plugin.trusted_keys', base_path() . '/config/plugin_keys'),
                PluginManager::registry(),
                (int)config('payment_plugin.max_package_size', 20 * 1024 * 1024),
                (int)config('payment_plugin.max_file_size', 5 * 1024 * 1024),
                (int)config('payment_plugin.max_files', 500),
            );
            $plugin = $installer->install($tmpFile);
            PaymentManager::flush();
            return json([
                'code' => 1,
                'msg'  => '插件下载并安装成功，请在插件列表中启用',
                'data' => $plugin,
            ]);
        } catch (PluginException $e) {
            return json(['code' => -1, 'msg' => '插件安装失败：' . $e->getMessage()]);
        } catch (\Throwable $e) {
            return json(['code' => -1, 'msg' => '插件安装异常，请查看服务端日志'])->withStatus(500);
        } finally {
            @unlink($tmpFile);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 私有辅助方法
    // ─────────────────────────────────────────────────────────────────

    /**
     * 读取本站点的域名、auth_key 与官方云端地址配置。
     *
     * @return array{0:string,1:string,2:string,3:?Response}
     */
    private function siteAuthParams(): array
    {
        $domain   = strtolower(trim((string)config('app.site_domain', '')));
        $authKey  = trim((string)config('app.auth_key', ''));
        $cloudUrl = trim((string)config('app.cloud_url', 'https://cloud.cxpay.com'));

        if ($domain === '' || $authKey === '') {
            return [$domain, $authKey, $cloudUrl,
                json(['code' => -1, 'msg' => '本站尚未配置 site_domain 或 auth_key，请先完成云端授权设置'])];
        }

        return [$domain, $authKey, $cloudUrl, null];
    }

    /** 发送 GET 请求到云端并解析 JSON 响应 */
    private function cloudGet(string $url, array $params = []): array
    {
        if ($params !== []) {
            $url .= '?' . http_build_query($params);
        }
        return $this->cloudRequest('GET', $url, []);
    }

    /** 发送 POST 请求到云端并解析 JSON 响应 */
    private function cloudPost(string $url, array $data = []): array
    {
        return $this->cloudRequest('POST', $url, $data);
    }

    private function cloudRequest(string $method, string $url, array $data): array
    {
        $opts = [
            'http' => [
                'method'        => $method,
                'timeout'       => 15,
                'ignore_errors' => true,
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: CXPAY-Agent/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ];
        if ($method === 'POST' && $data !== []) {
            $opts['http']['content'] = http_build_query($data);
        }

        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) {
            throw new \RuntimeException("HTTP 请求失败: {$url}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("云端返回了非 JSON 响应");
        }
        return $decoded;
    }
}

