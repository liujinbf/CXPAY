<?php

declare(strict_types=1);

namespace app\service;

use app\model\License;
use Exception;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * 商业源码水印打标与泄露溯源追踪器 (Watermark & Anti-Leak Tracer)
 */
class WatermarkTracerService
{
    /** @var string|null 盐值静态缓存，同进程内只读一次数据库 */
    private static ?string $saltCache = null;

    /**
     * 读取水印混淆盐值。
     *
     * 优先级：
     *   1. cx_config 表的 watermark_salt 配置项（在管理后台 → 系统配置中设置）
     *   2. WATERMARK_SALT 环境变量
     *   3. 内置默认值（向后兼容，生产环境强烈建议通过前两项覆盖）
     */
    private function getSalt(): string
    {
        if (self::$saltCache !== null) {
            return self::$saltCache;
        }

        // 1. 从数据库 cx_config 表读取
        try {
            $dbSalt = (string)(DB::table('cx_config')
                ->where('name', 'watermark_salt')
                ->value('value') ?? '');
            if (strlen($dbSalt) >= 16) {
                return self::$saltCache = $dbSalt;
            }
        } catch (\Throwable) {}

        // 2. 从环境变量读取
        $envSalt = (string)getenv('WATERMARK_SALT');
        if (strlen($envSalt) >= 16) {
            return self::$saltCache = $envSalt;
        }

        // 3. 内置默认值（向后兼容，生产环境建议在 cx_config 表或 .env 中覆盖）
        return self::$saltCache = 'CX_PAY_SALT_2026_SECRET_888';
    }

    /**
     * 为指定买家域名和身份生成独一无二的代码哈希水印指纹
     *
     * @param string $domain     买家域名 (如 m.fcwan.cn)
     * @param string $licenseKey 买家授权 Key
     * @return array{watermark_id: string, code_signature: string}
     */
    public function generateWatermark(string $domain, string $licenseKey): array
    {
        // 结合域名、密钥与全局混淆盐生成唯一的水印指纹
        $salt        = $this->getSalt();
        $watermarkId = 'WM_' . strtoupper(substr(md5($domain . $licenseKey . $salt), 0, 16));

        // 生成植入核心文件中的隐蔽水印注释签名
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
     * 当在外部（如盗版论坛、GitHub）发现泄露的代码片段时，
     * 解析其中的水印指纹并追踪泄露买家身份。
     *
     * 性能优化策略：
     *   1. 快速路径：若 cx_license 表有 watermark_id 列（索引），O(1) 查询直接命中
     *   2. 降级路径：全量加载后哈希比对，带进程内静态缓存，避免同次请求重复计算
     *
     * @param  string $leakedCodeSnippet 泄露的代码文本
     * @return array{leaked: bool, watermark_id?: string, domain?: string, license_key?: string, msg: string}
     */
    public function traceLeakedCode(string $leakedCodeSnippet): array
    {
        // 从泄露源码中查找 WatermarkID 标记
        if (!preg_match('/WatermarkID:\s*(WM_[A-Z0-9]{16})/', $leakedCodeSnippet, $matches)) {
            return ['leaked' => false, 'msg' => '未查找到已知买家的代码水印'];
        }

        $watermarkId = $matches[1];

        // 【快速路径】若 License 表已添加 watermark_id 列并建立索引，直接 SQL 查询
        try {
            $lic = License::where('watermark_id', $watermarkId)->first();
            if ($lic) {
                return [
                    'leaked'       => true,
                    'watermark_id' => $watermarkId,
                    'domain'       => $lic->domain,
                    'license_key'  => $lic->auth_key,
                    'msg'          => "成功定位泄露源头！泄露买家域名: [{$lic->domain}]",
                ];
            }
        } catch (\Throwable) {
            // watermark_id 列尚未存在时，静默回落到全量哈希匹配
        }

        // 【降级路径】全量加载并逐条哈希比对，带静态缓存避免同请求重复计算
        /** @var array<string, array{domain: string, license_key: string}>|null $hashCache */
        static $hashCache = null;
        if ($hashCache === null) {
            $hashCache = [];
            foreach (License::all() as $lic) {
                $check = $this->generateWatermark($lic->domain, $lic->auth_key);
                $hashCache[$check['watermark_id']] = [
                    'domain'      => $lic->domain,
                    'license_key' => $lic->auth_key,
                ];
            }
        }

        if (isset($hashCache[$watermarkId])) {
            $info = $hashCache[$watermarkId];
            return [
                'leaked'       => true,
                'watermark_id' => $watermarkId,
                'domain'       => $info['domain'],
                'license_key'  => $info['license_key'],
                'msg'          => "成功定位泄露源头！泄露买家域名: [{$info['domain']}]",
            ];
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

        $license->status      = 0;
        $license->update_time = time();
        $license->save();

        return true;
    }
}
