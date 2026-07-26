<?php

declare(strict_types=1);

namespace app\service;

use app\model\License;
use app\model\LicenseOrder;
use app\service\WatermarkTracerService;
use Exception;

/**
 * 官方云端授权控制中心服务 (集成动态源码水印与泄露一键封禁)
 */
class CloudLicenseService
{
    protected WatermarkTracerService $tracer;

    public function __construct()
    {
        $this->tracer = new WatermarkTracerService();
    }

    /**
     * 为指定买家生成带有独立身份水印的源码打包指纹
     */
    public function buildWatermarkedDownloadPackage(string $domain): array
    {
        $license = License::where('domain', $domain)->first();
        if (!$license || (int)$license->status !== 1) {
            throw new Exception("站点 [{$domain}] 未授权或已被封禁，无法下载源码包");
        }

        $watermark = $this->tracer->generateWatermark($domain, $license->auth_key);

        return [
            'domain'         => $domain,
            'watermark_id'   => $watermark['watermark_id'],
            'code_signature' => $watermark['code_signature'],
            'download_url'   => "/scratch/pc_project/CXPayAssistant_v4.exe",
        ];
    }

    /**
     * 一键追踪并封禁泄露源码的买家
     */
    public function traceAndBanLeakedCode(string $codeSnippet): array
    {
        $traceRes = $this->tracer->traceLeakedCode($codeSnippet);
        if ($traceRes['leaked']) {
            // 自动封禁该买家
            $this->tracer->banLeakedLicense($traceRes['domain']);
            $traceRes['banned'] = true;
            $traceRes['msg'] .= ' ➔ 已自动封禁并拉黑该买家账号及其云端免挂授权！';
        }

        return $traceRes;
    }

    /**
     * 校验买家站点的域名授权与特定云端免挂模块订阅是否生效
     */
    public function validateSiteAuth(string $domain, string $authKey, string $moduleKey): array
    {
        $license = License::where('domain', $domain)->first();
        if (!$license) {
            return [
                'valid'       => false,
                'msg'         => "站点 [{$domain}] 未获得官方授权，请联系官方开启授权",
                'expire_time' => 0
            ];
        }

        if ((int)$license->status !== 1) {
            return [
                'valid'       => false,
                'msg'         => "⚠️ 警告：该站点 [{$domain}] 因泄露源码或违规已被官方永久封禁拉黑！",
                'expire_time' => 0
            ];
        }

        if ($license->auth_key !== $authKey) {
            return [
                'valid'       => false,
                'msg'         => "授权密钥 (auth_key) 校验失败，请检查设置",
                'expire_time' => 0
            ];
        }

        $modules = !empty($license->modules) ? json_decode($license->modules, true) : [];
        $expireTime = (int)($modules[$moduleKey] ?? 0);

        if ($expireTime < time() && $expireTime !== -1) {
            return [
                'valid'       => false,
                'msg'         => "模块 [{$moduleKey}] 订阅已到期，请前往云端授权中心续费",
                'expire_time' => $expireTime
            ];
        }

        return [
            'valid'       => true,
            'msg'         => '授权验证通过',
            'expire_time' => $expireTime
        ];
    }

    public function renewModuleSubscription(string $domain, string $moduleKey, string $pkgType): array
    {
        $license = License::where('domain', $domain)->first();
        if (!$license) {
            $license = License::create([
                'domain'      => $domain,
                'auth_key'    => 'CX_KEY_' . substr(md5($domain . time()), 0, 16),
                'agent_id'    => 1,
                'modules'     => json_encode([]),
                'status'      => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        $modules = !empty($license->modules) ? json_decode($license->modules, true) : [];
        $currentExpire = (int)($modules[$moduleKey] ?? time());

        if ($currentExpire < time()) {
            $currentExpire = time();
        }

        $addSeconds = match ($pkgType) {
            'month'   => 30 * 86400,
            'quarter' => 90 * 86400,
            'year'    => 365 * 86400,
            'forever' => -1,
            default   => 30 * 86400,
        };

        $newExpire = ($addSeconds === -1) ? -1 : ($currentExpire + $addSeconds);
        $modules[$moduleKey] = $newExpire;

        $license->modules     = json_encode($modules);
        $license->update_time = time();
        $license->save();

        return [
            'code'        => 1,
            'msg'         => "模块 [{$moduleKey}] 成功续费至 " . ($newExpire === -1 ? '永久授权' : date('Y-m-d H:i:s', $newExpire)),
            'expire_time' => $newExpire,
        ];
    }

    public function resetAuthKey(string $domain): string
    {
        $license = License::where('domain', $domain)->first();
        if (!$license) {
            throw new Exception('授权站点不存在');
        }

        $newKey = 'CX_KEY_' . substr(md5($domain . microtime()), 0, 16);
        $license->auth_key    = $newKey;
        $license->update_time = time();
        $license->save();

        return $newKey;
    }

    public function changeDomain(string $oldDomain, string $newDomain): bool
    {
        $license = License::where('domain', $oldDomain)->first();
        if (!$license) {
            throw new Exception('原授权域名不存在');
        }

        if (!empty($license->update_time) && (time() - $license->update_time) < (7 * 86400)) {
            throw new Exception('为防止滥用，更换域名每 7 天只能操作一次');
        }

        $license->domain      = $newDomain;
        $license->update_time = time();
        $license->save();

        return true;
    }
}
