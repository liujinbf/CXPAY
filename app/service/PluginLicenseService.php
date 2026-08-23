<?php

declare(strict_types=1);

namespace app\service;

use app\model\AgentPluginLicense;
use app\model\CloudPlugin;
use app\model\License;
use Exception;

/**
 * 插件授权服务
 *
 * 职责：
 *   1. 校验代理站点是否拥有某个插件的有效授权（供下载、安装时调用）
 *   2. 为代理端购买插件授权（创建或续期 cx_agent_plugin_license 记录）
 *   3. 返回代理站点已购插件列表及其到期状态
 */
class PluginLicenseService
{
    /**
     * 校验代理站点是否具备指定插件的有效授权。
     *
     * 调用场景：
     *   - 代理端向云端请求下载插件包时
     *   - 安装完成后的本地联网二次校验（可选）
     *
     * @param string $domain  代理站点域名
     * @param string $authKey 代理站点授权密钥
     * @param string $pluginId 插件标识，如 cxpay.wxpay.clerk
     * @return array{valid:bool, msg:string, expire_time:int}
     */
    public function validatePluginAuth(string $domain, string $authKey, string $pluginId): array
    {
        // 第一步：验证站点 License 本身是否有效
        $license = License::where('domain', $domain)->first();
        if (!$license) {
            return [
                'valid'       => false,
                'msg'         => "站点 [{$domain}] 未获得官方授权，请联系官方开启站点授权",
                'expire_time' => 0,
            ];
        }
        if ((int)$license->status !== 1) {
            return [
                'valid'       => false,
                'msg'         => "站点 [{$domain}] 已被封禁，无法使用任何插件",
                'expire_time' => 0,
            ];
        }
        if ($license->auth_key !== $authKey) {
            return [
                'valid'       => false,
                'msg'         => '授权密钥 (auth_key) 校验失败，请检查配置',
                'expire_time' => 0,
            ];
        }

        // 第二步：查询该域名对应插件的授权记录
        $pluginLicense = AgentPluginLicense::where('domain', $domain)
            ->where('plugin_id', $pluginId)
            ->first();

        if (!$pluginLicense) {
            return [
                'valid'       => false,
                'msg'         => "插件 [{$pluginId}] 尚未购买，请在插件商城购买授权后再下载安装",
                'expire_time' => 0,
            ];
        }

        $expire = (int)$pluginLicense->expire_time;

        // 永久授权
        if ($expire === -1) {
            return [
                'valid'       => true,
                'msg'         => '插件永久授权有效',
                'expire_time' => -1,
            ];
        }

        // 已到期
        if ($expire < time()) {
            return [
                'valid'       => false,
                'msg'         => "插件 [{$pluginId}] 授权已到期（" . date('Y-m-d', $expire) . "），请续费后再使用",
                'expire_time' => $expire,
            ];
        }

        $daysLeft = (int)ceil(($expire - time()) / 86400);
        return [
            'valid'       => true,
            'msg'         => "插件授权有效，剩余 {$daysLeft} 天",
            'expire_time' => $expire,
        ];
    }

    /**
     * 为代理站点购买/续期插件授权。
     *
     * 计费由调用方（Controller）完成后调用此方法写入授权记录。
     * 此方法只负责创建或更新 cx_agent_plugin_license。
     *
     * @param string $domain   代理站点域名
     * @param string $pluginId 插件标识
     * @param string $pkgType  套期类型：month / quarter / year / forever
     * @param float  $amount   实付金额
     * @return array{expire_time:int, expire_label:string}
     * @throws Exception
     */
    public function grantPluginLicense(
        string $domain,
        string $pluginId,
        string $pkgType,
        float  $amount
    ): array {
        $addSeconds = match ($pkgType) {
            'month'   => 30 * 86400,
            'quarter' => 90 * 86400,
            'year'    => 365 * 86400,
            'forever' => -1,
            default   => throw new Exception("不支持的套期类型：{$pkgType}"),
        };

        $existing = AgentPluginLicense::where('domain', $domain)
            ->where('plugin_id', $pluginId)
            ->first();

        $now = time();

        if ($addSeconds === -1) {
            // 买断：直接覆盖为永久
            $newExpire = -1;
        } else {
            // 续期：在现有到期时间基础上叠加；若已过期则从当前时间起算
            $currentExpire = $existing ? (int)$existing->expire_time : 0;
            if ($currentExpire === -1) {
                // 已是永久授权，续费无意义——直接返回
                return ['expire_time' => -1, 'expire_label' => '永久授权'];
            }
            $base = ($currentExpire > $now) ? $currentExpire : $now;
            $newExpire = $base + $addSeconds;
        }

        if ($existing) {
            $existing->pkg_type    = $pkgType;
            $existing->amount      = number_format($amount, 2, '.', '');
            $existing->expire_time = $newExpire;
            $existing->save();
            $record = $existing;
        } else {
            $record = AgentPluginLicense::create([
                'domain'      => $domain,
                'plugin_id'   => $pluginId,
                'pkg_type'    => $pkgType,
                'amount'      => number_format($amount, 2, '.', ''),
                'expire_time' => $newExpire,
                'create_time' => $now,
            ]);
        }

        $expireLabel = $newExpire === -1
            ? '永久授权'
            : date('Y-m-d', $newExpire) . '（剩余 ' . (int)ceil(($newExpire - $now) / 86400) . ' 天）';

        return [
            'expire_time'  => $newExpire,
            'expire_label' => $expireLabel,
        ];
    }

    /**
     * 获取代理站点所有已购插件的授权状态列表。
     *
     * @param string $domain 代理站点域名
     * @return list<array{plugin_id:string, name:string, expire_time:int, expire_label:string, valid:bool}>
     */
    public function listAgentPlugins(string $domain): array
    {
        $licenses = AgentPluginLicense::where('domain', $domain)
            ->orderBy('create_time', 'desc')
            ->get();

        // 预取插件商品信息（批量减少查询）
        $pluginIds = $licenses->pluck('plugin_id')->toArray();
        $pluginMap = CloudPlugin::whereIn('plugin_id', $pluginIds)
            ->get()
            ->keyBy('plugin_id')
            ->toArray();

        $result = [];
        foreach ($licenses as $lic) {
            $pluginInfo = $pluginMap[$lic->plugin_id] ?? [];
            $expire     = (int)$lic->expire_time;
            $valid      = ($expire === -1 || $expire > time());
            $expireLabel = $expire === -1
                ? '永久授权'
                : ($valid
                    ? date('Y-m-d', $expire) . '（剩余 ' . (int)ceil(($expire - time()) / 86400) . ' 天）'
                    : '已到期（' . date('Y-m-d', $expire) . '）');

            $result[] = [
                'plugin_id'    => $lic->plugin_id,
                'name'         => $pluginInfo['name'] ?? $lic->plugin_id,
                'payment_type' => $pluginInfo['payment_type'] ?? '',
                'pkg_type'     => $lic->pkg_type,
                'amount'       => $lic->amount,
                'expire_time'  => $expire,
                'expire_label' => $expireLabel,
                'valid'        => $valid,
            ];
        }

        return $result;
    }

    /**
     * 获取云端插件商品列表（供代理端插件商城展示）。
     *
     * @param string $domain 代理站点域名（用于标注哪些已购买）
     * @return array
     */
    public function getCloudMarket(string $domain): array
    {
        $plugins = CloudPlugin::where('status', 1)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // 获取该域名已购买的插件及到期信息
        $purchased = AgentPluginLicense::where('domain', $domain)
            ->get()
            ->keyBy('plugin_id');

        return $plugins->map(function (CloudPlugin $plugin) use ($purchased): array {
            $lic     = $purchased->get($plugin->plugin_id);
            $expire  = $lic ? (int)$lic->expire_time : 0;
            $valid   = $lic && ($expire === -1 || $expire > time());

            return [
                'plugin_id'     => $plugin->plugin_id,
                'name'          => $plugin->name,
                'description'   => $plugin->description,
                'payment_type'  => $plugin->payment_type,
                'version'       => $plugin->version,
                'price_month'   => (float)$plugin->price_month,
                'price_quarter' => (float)$plugin->price_quarter,
                'price_year'    => (float)$plugin->price_year,
                'price_forever' => (float)$plugin->price_forever,
                'is_free'       => $plugin->isFree(),
                'purchased'     => (bool)$lic,
                'license_valid' => $valid,
                'expire_time'   => $expire,
                'expire_label'  => $lic
                    ? ($expire === -1 ? '永久授权' : ($valid
                        ? date('Y-m-d', $expire) . ' 到期'
                        : '已到期'))
                    : '未购买',
            ];
        })->values()->toArray();
    }

    /**
     * 校验当前主站节点是否已开通/已授权指定的支付通道驱动。
     * 
     * 规则：
     * 1. 支付宝三大核心驱动（当面付 alipay_face_pay、免挂云端 alipay_cookie_cloud、免挂助手 alipay_app_asst）：默认永久免费已开通；
     * 2. 其他付费通道（微信/QQ/USDT/易支付等）：必须在主站本地授权凭据 entitlements.json 或数据库已购买列表中存在有效授权；
     * 3. 未开通的通道主站商户端严格不可见、不可添加。
     */
    public static function isChannelEntitled(string $cType): bool
    {
        $cType = trim($cType);
        if ($cType === '' || \app\payment\RemovedPaymentDrivers::contains($cType)) {
            return false;
        }

        // 1. 支付宝三大通道插件默认免费授权
        $freeChannels = ['alipay_face_pay', 'alipay_cookie_cloud', 'alipay_app_asst'];
        if (in_array($cType, $freeChannels, true)) {
            return true;
        }

        // 2. 检查本地 Ed25519 授权凭据文件
        $entitlementFile = runtime_path() . '/instance/entitlements.json';
        if (file_exists($entitlementFile)) {
            $entitlements = json_decode((string)file_get_contents($entitlementFile), true);
            if (is_array($entitlements)) {
                if (isset($entitlements[$cType]) || isset($entitlements['cxpay.driver.' . $cType])) {
                    return true;
                }
            }
        }

        // 3. 检查当前域名是否已购买过代理授权
        try {
            $hasDbLic = AgentPluginLicense::where(function ($q) use ($cType) {
                $q->where('plugin_id', $cType)
                  ->orWhere('plugin_id', 'cxpay.driver.' . $cType);
            })->where(function ($q) {
                $q->where('expire_time', -1)->orWhere('expire_time', '>', time());
            })->exists();

            if ($hasDbLic) {
                return true;
            }
        } catch (\Throwable) {
            // 忽略数据库查询偶发异常
        }

        return false;
    }
}

