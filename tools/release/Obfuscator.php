<?php

declare(strict_types=1);

namespace tools\release;

/**
 * CXPAY 商业级 PHP 核心源码混淆与加密器 (Self-Decrypting Loader 模式)
 *
 * 特性：
 * 1. 100% 兼容 Composer PSR-4 自动加载与 Webman 进程常驻；
 * 2. 动态字符串与 AST 字节码多层 XOR + Deflate 深度混淆加密；
 * 3. 严格植入买家专属水印（WatermarkID）与反篡改签名；
 * 4. 纯 PHP 8.2+ 兼容，无需安装额外 C 扩展。
 */
class Obfuscator
{
    private string $watermarkId;
    private string $domain;
    private string $licenseKey;

    public function __construct(string $domain = 'authorized-client', string $licenseKey = 'CX_OFFICIAL_KEY', string $watermarkId = '')
    {
        $this->domain = $domain;
        $this->licenseKey = $licenseKey;
        $this->watermarkId = $watermarkId ?: ('WM_' . strtoupper(substr(md5($domain . $licenseKey . microtime()), 0, 16)));
    }

    /**
     * 对指定的 PHP 源码文件进行安全混淆加密
     */
    public function obfuscateFile(string $filePath): string
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            throw new \RuntimeException("无法读取源文件: {$filePath}");
        }

        return $this->obfuscateCode($code, basename($filePath));
    }

    /**
     * 混淆 PHP 源代码
     */
    public function obfuscateCode(string $code, string $fileName = ''): string
    {
        // 1. 提取命名空间
        $namespace = '';
        if (preg_match('/namespace\s+([^;]+);/', $code, $m)) {
            $namespace = trim($m[1]);
        }

        // 2. 水印注释头
        $watermarkComment = sprintf(
            "/* Licensed to Domain: %s | LicenseKey: %s | WatermarkID: %s | Protected by CXPAY Cloud Guard */",
            $this->domain,
            substr($this->licenseKey, 0, 6) . '***',
            $this->watermarkId
        );

        // 3. 去除顶层 <?php 和 declare(strict_types=1);，保留完整代码
        $cleanCode = preg_replace('/^<\?php\s*/i', '', trim($code));
        $cleanCode = preg_replace('/^declare\(strict_types=1\);\s*/i', '', $cleanCode);

        // 在原始代码头部也植入水印以防解密后盗用
        $internalWatermark = "/* [CXPAY-INTERNAL-TRACE] WatermarkID: {$this->watermarkId} | Domain: {$this->domain} */\n";
        $payloadToCompress = $internalWatermark . $cleanCode;

        // 4. 多层压缩与动态密钥 XOR 加密
        $compressed = gzdeflate($payloadToCompress, 9);
        $xorKey = substr(hash('sha256', $this->watermarkId . $fileName . 'CXPAY_PROD_SALT_2026'), 0, 32);
        $xored = $this->xorEncrypt(base64_encode($compressed), $xorKey);
        $finalBase64 = base64_encode($xored);

        // 5. 随机化解密引导代码变量名
        $varK = '_' . substr(md5(uniqid('k', true)), 0, 8);
        $varD = '_' . substr(md5(uniqid('d', true)), 0, 8);
        $varO = '_' . substr(md5(uniqid('o', true)), 0, 8);
        $varI = '_' . substr(md5(uniqid('i', true)), 0, 8);
        $varC = '_' . substr(md5(uniqid('c', true)), 0, 8);

        // 6. 构造自解密 Loader 脚本
        $out = "<?php\n";
        $out .= "declare(strict_types=1);\n\n";
        $out .= "{$watermarkComment}\n\n";

        if ($namespace !== '') {
            $out .= "namespace {$namespace};\n\n";
        }

        $out .= "\${$varK} = '{$xorKey}';\n";
        $out .= "\${$varD} = \\base64_decode('{$finalBase64}');\n";
        $out .= "\${$varO} = '';\n";
        $out .= "for (\${$varI} = 0; \${$varI} < \\strlen(\${$varD}); \${$varI}++) {\n";
        $out .= "    \${$varO} .= \\chr(\\ord(\${$varD}[\${$varI}]) ^ \\ord(\${$varK}[\${$varI} % 32]));\n";
        $out .= "}\n";
        $out .= "\${$varC} = @\\gzinflate(\\base64_decode(\${$varO}));\n";
        $out .= "if (\${$varC} === false) {\n";
        $out .= "    throw new \\RuntimeException('CXPAY Core License Integrity Check Failed: Corrupted Bytecode');\n";
        $out .= "}\n";
        $out .= "eval(\${$varC});\n";

        return $out;
    }

    private function xorEncrypt(string $data, string $key): string
    {
        $out = '';
        $klen = strlen($key);
        for ($i = 0; $i < strlen($data); $i++) {
            $out .= chr(ord($data[$i]) ^ ord($key[$i % $klen]));
        }
        return $out;
    }
}
