<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\CloudLicenseService;
use app\service\PluginLicenseService;
use app\model\CloudPlugin;
use support\Response;
use Throwable;

/**
 * 云端授权中心 API 控制器 (含 QQ / 微信扫码授权与邮箱绑定)
 */
class CloudLicenseController
{
    protected CloudLicenseService $licenseService;

    public function __construct()
    {
        $this->licenseService = new CloudLicenseService();
    }

    /**
     * 发起微信扫码登录授权会话 /api/cloud/wx_login_qr
     */
    public function getWxLoginQr(): Response
    {
        return $this->providerUnavailable('微信扫码登录');
    }

    /**
     * 轮询微信扫码状态 /api/cloud/poll_wx_login
     */
    public function pollWxLogin(\support\Request $request): Response
    {
        return $this->providerUnavailable('微信扫码登录');
    }

    public function getQqLoginQr(): Response
    {
        return $this->providerUnavailable('QQ扫码登录');
    }

    public function pollQqLogin(\support\Request $request): Response
    {
        return $this->providerUnavailable('QQ扫码登录');
    }

    public function sendEmailCode(\support\Request $request): Response
    {
        return $this->providerUnavailable('邮件验证码');
    }

    public function bindQq(\support\Request $request): Response
    {
        return $this->providerUnavailable('邮件绑定');
    }

    public function downloadPackage(\support\Request $request): Response
    {
        try {
            $domain = (string)($request->get('domain') ?? $request->host() ?? 'm.fcwan.cn');
            $res = $this->licenseService->buildWatermarkedDownloadPackage($domain);
            return json(['code' => 1, 'msg' => '生成水印下载包成功', 'data' => $res]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function traceLeaked(\support\Request $request): Response
    {
        try {
            $codeSnippet = (string)($request->post('code') ?? '');
            if (empty($codeSnippet)) {
                return json(['code' => -1, 'msg' => '请粘贴泄漏的代码片段']);
            }

            $res = $this->licenseService->traceAndBanLeakedCode($codeSnippet);
            return json(['code' => 1, 'data' => $res]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function getSiteInfo(\support\Request $request): Response
    {
        // cloud_auth.html 无参调用时，使用 Host 头确定域名；也接受显式 domain 参数
        $domain = strtolower(trim((string)($request->get('domain') ?? $request->host()), " .\t\n\r\0\x0B"));

        if (!$this->validDomain($domain)) {
            return json(['code' => -1, 'msg' => '无法识别当前请求域名'])->withStatus(400);
        }

        $license = \app\model\License::where('domain', $domain)->first();

        if (!$license) {
            return json([
                'code' => -1,
                'msg'  => "站点 [{$domain}] 未获得官方授权，请联系官方开启授权",
                'data' => ['domain' => $domain, 'authorized' => false],
            ]);
        }

        if ((int)$license->status !== 1) {
            return json([
                'code' => -1,
                'msg'  => "站点 [{$domain}] 已被封禁，请联系官方申诉",
                'data' => ['domain' => $domain, 'authorized' => false, 'banned' => true],
            ]);
        }

        // 解析模块订阅信息
        $modulesRaw = !empty($license->modules)
            ? (json_decode((string)$license->modules, true) ?: [])
            : [];

        $moduleLabels = [
            'vip_member'        => '云端会员',
            'ipad_cloud'        => '官方 iPad 云端',
            'wxpay_pc'          => '微信 PC 挂机',
            'android_app'       => '安卓 APP 挂机',
            'wx_protocol_cloud' => '官方微信协议云端',
            'ios_hang'          => 'iOS 挂机',
            'win_white_monitor' => 'Win 白挂监控',
            'wx_agent_terminal' => '微信代挂终端',
        ];

        $modules = [];
        foreach ($moduleLabels as $key => $name) {
            $expire = (int)($modulesRaw[$key] ?? 0);
            if ($expire === -1) {
                $status = '永久授权';
            } elseif ($expire > time()) {
                $daysLeft = (int)ceil(($expire - time()) / 86400);
                $status   = "授权有效（剩余 {$daysLeft} 天）";
            } else {
                $status = '已到期';
            }
            $modules[] = [
                'name'        => $name,
                'key'         => $key,
                'status'      => $status,
                'expire_time' => $expire,
            ];
        }

        // 密钥掩码（保留前 8 位 + *** + 后 4 位）
        $authKey   = (string)$license->auth_key;
        $keyMasked = strlen($authKey) > 12
            ? substr($authKey, 0, 8) . '***' . substr($authKey, -4)
            : str_repeat('*', strlen($authKey));

        $vipExpire = (int)($modulesRaw['vip_member'] ?? 0);
        $isVip     = ($vipExpire === -1 || $vipExpire > time());

        // 已购插件授权列表（聚合 cx_agent_plugin_license 中该域名的所有记录）
        $pluginLicenseSvc  = new PluginLicenseService();
        $purchasedPlugins  = $pluginLicenseSvc->listAgentPlugins($domain);

        return json([
            'code' => 1,
            'data' => [
                'domain'            => $domain,
                'authorized'        => true,
                'key_masked'        => $keyMasked,
                'key_full'          => $authKey,   // 授权中心场景需完整密钥供配置复制
                'watermark_id'      => (string)($license->watermark_id ?? ''),
                'vip'               => $isVip,
                'modules'           => $modules,
                'purchased_plugins' => $purchasedPlugins, // 已购插件授权列表
                'status'            => 'authorized',
            ],
        ]);
    }

    public function renewModule(\support\Request $request): Response
    {
        try {
            $domain    = (string)($request->post('domain') ?? $request->host() ?? 'm.fcwan.cn');
            $moduleKey = (string)($request->post('module_key') ?? 'wx_protocol_cloud');
            $pkgType   = (string)($request->post('pkg_type') ?? 'month');

            $res = $this->licenseService->renewModuleSubscription($domain, $moduleKey, $pkgType);
            return json($res);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function resetKey(\support\Request $request): Response
    {
        try {
            $domain = (string)($request->post('domain') ?? $request->host() ?? 'm.fcwan.cn');
            $newKey = $this->licenseService->resetAuthKey($domain);
            return json(['code' => 1, 'msg' => '授权 Key 重置成功！', 'new_key' => $newKey]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    public function changeDomain(\support\Request $request): Response
    {
        try {
            $oldDomain = (string)($request->post('old_domain') ?? '');
            $newDomain = (string)($request->post('new_domain') ?? '');

            if (empty($oldDomain) || empty($newDomain)) {
                return json(['code' => -1, 'msg' => '原域名与新域名不能为空']);
            }

            $this->licenseService->changeDomain($oldDomain, $newDomain);
            return json(['code' => 1, 'msg' => "授权域名已成功更换为 [{$newDomain}]！"]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Phase 3：官方云端侧 支付插件商城 API
    // ─────────────────────────────────────────────────────────────────

    /**
     * 云端插件商城商品列表 API
     * GET /api/cloud/plugin/market_list?domain=xxx&auth_key=xxx
     */
    public function pluginMarketList(\support\Request $request): Response
    {
        try {
            $domain  = strtolower(trim((string)($request->get('domain') ?? '')));
            $authKey = trim((string)($request->get('auth_key') ?? ''));

            if (!$this->validDomain($domain) || empty($authKey)) {
                return json(['code' => -1, 'msg' => '域名或授权 Key 不合法'])->withStatus(400);
            }

            $pluginLicenseSvc = new PluginLicenseService();
            $data = $pluginLicenseSvc->getCloudMarket($domain);

            return json(['code' => 1, 'msg' => '获取云端插件列表成功', 'data' => $data]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '拉取云端插件列表失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 云端插件购买/订阅授权 API
     * POST /api/cloud/plugin/buy
     */
    public function pluginBuy(\support\Request $request): Response
    {
        try {
            $domain   = strtolower(trim((string)($request->post('domain') ?? '')));
            $authKey  = trim((string)($request->post('auth_key') ?? ''));
            $pluginId = trim((string)($request->post('plugin_id') ?? ''));
            $pkgType  = trim((string)($request->post('pkg_type') ?? 'month'));

            if (!$this->validDomain($domain) || empty($authKey) || empty($pluginId)) {
                return json(['code' => -1, 'msg' => '必填参数缺失或不合法']);
            }

            // 1. 验证站点 License 与 auth_key
            $siteAuth = $this->licenseService->validateSiteAuth($domain, $authKey, 'vip_member');
            if (!$siteAuth['valid'] && !str_contains($siteAuth['msg'], '模块')) {
                // 如果是站点未授权或封禁、auth_key 错误直接拒绝
                return json(['code' => -1, 'msg' => $siteAuth['msg']]);
            }

            // 2. 查插件商品表
            $cloudPlugin = CloudPlugin::where('plugin_id', $pluginId)->where('status', 1)->first();
            if (!$cloudPlugin) {
                return json(['code' => -1, 'msg' => '插件不存在或已下架']);
            }

            $price = $cloudPlugin->priceFor($pkgType);

            // 3. 赋予授权记录
            $pluginLicenseSvc = new PluginLicenseService();
            $res = $pluginLicenseSvc->grantPluginLicense($domain, $pluginId, $pkgType, $price);

            return json([
                'code' => 1,
                'msg'  => "成功购买插件 [{$cloudPlugin->name}] 授权！",
                'data' => $res,
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '购买插件授权失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 云端插件包二进制下载 API
     * GET /api/cloud/plugin/download?domain=xxx&auth_key=xxx&plugin_id=xxx
     */
    public function pluginDownload(\support\Request $request): Response
    {
        try {
            $domain   = strtolower(trim((string)($request->get('domain') ?? '')));
            $authKey  = trim((string)($request->get('auth_key') ?? ''));
            $pluginId = trim((string)($request->get('plugin_id') ?? ''));

            if (!$this->validDomain($domain) || empty($authKey) || empty($pluginId)) {
                return json(['code' => -1, 'msg' => '请求参数不完整']);
            }

            // 验证代理站点对于该插件的授权状态
            $pluginLicenseSvc = new PluginLicenseService();
            $check = $pluginLicenseSvc->validatePluginAuth($domain, $authKey, $pluginId);

            if (!$check['valid']) {
                return json(['code' => -1, 'msg' => $check['msg']])->withStatus(403);
            }

            // 寻找本地或存储中的 .cxpay-plugin 文件
            $pluginFile = base_path() . "/storage/plugins/{$pluginId}.cxpay-plugin";
            if (!file_exists($pluginFile)) {
                return json(['code' => -1, 'msg' => "云端插件包文件不存在或尚未构建完成 [{$pluginId}]"])->withStatus(444);
            }

            return response()->download($pluginFile, "{$pluginId}.cxpay-plugin");
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '插件包下载异常: ' . $e->getMessage()]);
        }
    }

    private function providerUnavailable(string $provider): Response
    {
        return json(['code' => -1, 'msg' => "{$provider}尚未配置真实服务，功能暂不可用"])->withStatus(501);
    }

    private function validDomain(string $domain): bool
    {
        return (bool)preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain);
    }
}

