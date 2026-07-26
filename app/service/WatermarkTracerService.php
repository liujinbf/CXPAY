<?php

declare(strict_types=1);

namespace app\service;

use app\model\License;
use Exception;

/**
 * 商业源码水印打标与泄露溯源追踪器 (Watermark & Anti-Leak Tracer)
 */
class WatermarkTracerService
{
    /**
     * 为指定买家域名和身份生成独一无二的代码哈希水印指纹
     *
     * @param string $domain 买家域名 (如 m.fcwan.cn)
     * @param string $licenseKey 买家授权 Key
     * @return array [watermark_id => string, code_signature => string]
     */
    public function generateWatermark(string $domain, string $licenseKey): array
    {
        // 1. 结合域名、密钥、时间戳与全局混淆盐生成唯一的水印指纹
        $salt = 'CX_PAY_SALT_2026_SECRET_888';
        $watermarkId = 'WM_' . strtoupper(substr(md5($domain . $licenseKey . $salt), 0, 16));

        // 2. 生成植入核心文件中的隐蔽水印注释签名
        $signature = sprintf(
            "/* Licensed to Domain: %s | LicenseKey: %s | WatermarkID: %s | Authorized */",
            $domain,
            substr($licenseKey, 0, 6) . '***',
            $watermarkId
        );

        return [
            'watermark_id'   => $watermarkId,
            'code_signature' => $signature,
        ];
    }

    /**
     * 当在外部（如盗版论坛、Github）发现泄露的代码片段时，解析其中的水印指纹并追踪泄露买家身份
     *
     * @param string $leakedCodeSnippet 泄露的代码文本
     * @return array [leaked => bool, domain => string, license_key => string]
     */
    public function traceLeakedCode(string $leakedCodeSnippet): array
    {
        // 从泄露源码中查找 WatermarkID 或签名
        if (preg_match('/WatermarkID:\s*(WM_[A-Z0-9]{16})/', $leakedCodeSnippet, $matches)) {
            $watermarkId = $matches[1];

            // 在 License 表中溯源匹配买家
            $licenses = License::all();
            foreach ($licenses as $lic) {
                $check = $this->generateWatermark($lic->domain, $lic->auth_key);
                if ($check['watermark_id'] === $watermarkId) {
                    return [
                        'leaked'      => true,
                        'watermark_id'=> $watermarkId,
                        'domain'      => $lic->domain,
                        'license_key' => $lic->auth_key,
                        'msg'         => "成功定位泄露源头！泄露买家域名: [{$lic->domain}]"
                    ];
                }
            }
        }

        return ['leaked' => false, 'msg' => '未查找到已知买家的代码水印'];
    }

    /**
     * 一键封禁并拉黑泄露源码的买家授权
     */
    public function banLeakedLicense(string $domain): bool
    {
        $license = License::where('domain', $domain)->first();
        if (!$license) {
            throw new Exception('未找到该授权站点');
        }

        // 将状态置为 0 (封禁拉黑)
        $license->status      = 0;
        $license->update_time = time();
        $license->save();

        return true;
    }
}
