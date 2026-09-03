<?php

declare(strict_types=1);

namespace app\service;

use app\payment\PaymentManager;
use app\payment\Plugin\PluginException;
use app\payment\Plugin\PluginManager;
use app\payment\Plugin\PluginPackageInstaller;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CloudInstanceClient
{
    private string $storageFile;
    private string $cloudApiUrl;
    private ?Client $httpClient;

    public function __construct(
        ?string $storageFile = null,
        ?string $cloudApiUrl = null,
        ?Client $httpClient = null
    ) {
        $this->storageFile = $storageFile ?? (runtime_path() . '/instance/identity.json');
        $rawUrl = rtrim($cloudApiUrl ?? (string)config('cloud.api_url', 'https://cloud.fcwan.cn'), '/');
        if (str_ends_with($rawUrl, '/api')) {
            $rawUrl = substr($rawUrl, 0, -4);
        }
        $this->cloudApiUrl = $rawUrl;
        $this->httpClient = $httpClient;
    }

    /**
     * 获取或初始化本地 Ed25519 实例身份
     *
     * @return array{
     *   instance_id: ?string,
     *   domain: ?string,
     *   public_key: string,
     *   secret_key: string,
     *   fingerprint: string,
     *   activated: bool,
     *   activated_at: ?string,
     *   license_type: string,
     *   is_agent: bool
     * }
     */
    public function getIdentity(): array
    {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (file_exists($this->storageFile)) {
            $data = json_decode((string)file_get_contents($this->storageFile), true);
            if (is_array($data) && !empty($data['public_key']) && !empty($data['secret_key'])) {
                $licenseType = strtoupper((string)($data['license_type'] ?? 'STANDARD'));
                $isAgent = (bool)($data['is_agent'] ?? ($licenseType === 'AGENT' || $licenseType === 'OEM'));
                return [
                    'instance_id' => $data['instance_id'] ?? null,
                    'domain' => $data['domain'] ?? null,
                    'public_key' => (string)$data['public_key'],
                    'secret_key' => (string)$data['secret_key'],
                    'fingerprint' => (string)($data['fingerprint'] ?? hash('sha256', self::base64UrlDecode((string)$data['public_key']))),
                    'activated' => (bool)($data['activated'] ?? false),
                    'activated_at' => $data['activated_at'] ?? null,
                    'license_type' => $licenseType,
                    'is_agent' => $isAgent,
                ];
            }
        }

        // 生成新的 Ed25519 密钥对
        $keys = self::generateKeyPair();
        $pubKeyBase64 = self::base64UrlEncode($keys['publicKey']);
        $secKeyBase64 = self::base64UrlEncode($keys['secretKey']);
        $fingerprint = hash('sha256', $keys['publicKey']);

        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $identity = [
            'instance_id' => null,
            'domain' => null,
            'public_key' => $pubKeyBase64,
            'secret_key' => $secKeyBase64,
            'fingerprint' => $fingerprint,
            'activated' => false,
            'activated_at' => null,
            'license_type' => 'STANDARD',
            'is_agent' => false,
        ];

        @file_put_contents($this->storageFile, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $identity;
    }

    /**
     * 检查当前实例是否具备 OEM 代理商资质（严禁未开通代理商资质的普通授权实例越权下发授权）
     */
    public function isAgent(): bool
    {
        $identity = $this->getIdentity();
        return ($identity['is_agent'] ?? false) === true;
    }

    /**
     * 获取当前实例授权等级类型 (STANDARD | AGENT)
     */
    public function getLicenseType(): string
    {
        $identity = $this->getIdentity();
        return (string)($identity['license_type'] ?? 'STANDARD');
    }

    /**
     * 刷新并持久化当前实例的代理商资质状态
     */
    public function updateAgentStatus(bool $isAgent, string $licenseType = 'STANDARD'): void
    {
        $identity = $this->getIdentity();
        $identity['is_agent'] = $isAgent;
        $identity['license_type'] = strtoupper($licenseType);
        @file_put_contents($this->storageFile, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 存量授权 Key 一键激活并绑定实例
     *
     * @return array{
     *   instance_id: string,
     *   domain: string,
     *   status: string,
     *   activated_at: string
     * }
     */
    public function activateWithLegacyKey(string $legacyKey, string $domain, string $productVersion = '1.0.0'): array
    {
        $identity = $this->getIdentity();
        $canonicalDomain = self::canonicalizeDomain($domain);

        // 1. 发起迁移请求
        $exchangeUrl = $this->cloudApiUrl . '/api/instance/v1/activations/exchange-legacy';
        $exchangeBody = [
            'legacy_key' => trim($legacyKey),
            'domain' => $canonicalDomain,
            'instance_public_key' => $identity['public_key'],
            'instance_fingerprint' => $identity['fingerprint'],
            'product_version' => $productVersion,
        ];

        $exchangeResponse = $this->httpPost($exchangeUrl, $exchangeBody);
        if (($exchangeResponse['code'] ?? 0) !== 1 || empty($exchangeResponse['data'])) {
            throw new RuntimeException((string)($exchangeResponse['msg'] ?? '云端激活申请失败'));
        }

        $data = $exchangeResponse['data'];
        $instanceId = (string)($data['instance_id'] ?? '');
        $activationId = (string)($data['activation_id'] ?? '');
        $challenge = (string)($data['challenge'] ?? '');

        if ($instanceId === '' || $activationId === '' || $challenge === '') {
            throw new RuntimeException('云端返回的激活挑战数据不完整');
        }

        // 2. 构造确认请求
        $confirmUrl = $this->cloudApiUrl . '/api/instance/v1/activations/confirm';
        $confirmPath = '/api/instance/v1/activations/confirm';
        $confirmBody = [
            'activation_id' => $activationId,
            'challenge' => $challenge,
            'domain' => $canonicalDomain,
        ];
        $rawBody = json_encode($confirmBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $timestamp = time();
        $nonce = self::base64UrlEncode(random_bytes(16));

        // 3. 计算 6 行规范串 Ed25519 签名
        $canonicalString = self::buildCanonicalString(
            httpMethod: 'POST',
            requestPath: $confirmPath,
            timestamp: $timestamp,
            nonce: $nonce,
            rawBody: $rawBody,
            instanceId: $instanceId
        );

        $secretKeyBytes = self::base64UrlDecode($identity['secret_key']);
        $signatureBytes = self::signDetached($canonicalString, $secretKeyBytes);
        $signatureBase64 = self::base64UrlEncode($signatureBytes);

        $headers = [
            'X-CXPAY-Instance' => $instanceId,
            'X-CXPAY-Timestamp' => (string)$timestamp,
            'X-CXPAY-Nonce' => $nonce,
            'X-CXPAY-Signature' => $signatureBase64,
            'Idempotency-Key' => 'idemp_' . bin2hex(random_bytes(12)),
            'Content-Type' => 'application/json',
        ];

        $confirmResponse = $this->httpPost($confirmUrl, $confirmBody, $headers);
        if (($confirmResponse['code'] ?? 0) !== 1 || empty($confirmResponse['data'])) {
            throw new RuntimeException((string)($confirmResponse['msg'] ?? '云端激活确认失败'));
        }

        $resultData = $confirmResponse['data'];

        // 4. 持久化激活结果
        $identity['instance_id'] = $instanceId;
        $identity['domain'] = $canonicalDomain;
        $identity['activated'] = true;
        $identity['activated_at'] = $resultData['activated_at'] ?? date('c');

        file_put_contents($this->storageFile, json_encode($identity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $resultData;
    }

    /**
     * 拉取云端插件市场全量商品目录与最新官方定价
     */
    public function fetchCatalog(): array
    {
        $identity = $this->getIdentity();
        if (($identity['activated'] ?? false) && !empty($identity['instance_id'])) {
            try {
                $res = $this->signedRequest('GET', '/api/instance/v1/plugins/catalog');
                if (($res['code'] ?? 0) === 1 && !empty($res['data']['plugins'])) {
                    return $res;
                }
            } catch (\Throwable) {
                // 实例签名请求失败时优雅降级至公开目录
            }
        }

        try {
            $url = $this->cloudApiUrl . '/api/ops/v1/plugins';
            $res = $this->httpGet($url);

            if (($res['code'] ?? 0) === 1 && !empty($res['data']['list'])) {
                $plugins = [];
                foreach ($res['data']['list'] as $p) {
                    $manifest = $p['manifest'] ?? [];
                    if (empty($manifest) && !empty($p['manifest_json'])) {
                        $manifest = json_decode((string)$p['manifest_json'], true) ?: [];
                    }
                    $pricing = $manifest['pricing'] ?? [];
                    $priceVal = (float)($pricing['price_standard'] ?? $p['price'] ?? $manifest['retail_price'] ?? 99.00);
                    $cType = (string)($p['c_type'] ?? $manifest['c_type'] ?? str_replace('cxpay.driver.', '', $p['plugin_id']));

                    if ($cType === 'clerk_adapter') $cType = 'wechat_dy_bill';
                    if ($cType === 'cloud_adapter') $cType = 'wechat_wecom_app';
                    if ($cType === 'scan_monitor') $cType = 'alipay_cookie_cloud';

                    $category = (string)($p['category'] ?? $manifest['category'] ?? (str_starts_with($cType, 'wx') || str_starts_with($cType, 'wechat') ? 'wxpay' : (str_starts_with($cType, 'ali') ? 'alipay' : (str_starts_with($cType, 'qq') ? 'qqpay' : 'other'))));
                    $isFree = $priceVal <= 0;

                    $plugins[] = [
                        'plugin_id'      => $p['plugin_id'],
                        'c_type'         => $cType,
                        'name'           => $p['name'],
                        'description'    => $p['description'],
                        'latest_version' => $p['latest_version'] ?? '1.0.0',
                        'publisher'      => $p['publisher'] ?? 'cxpay.official',
                        'category'       => $category,
                        'price'          => number_format($priceVal, 2, '.', ''),
                        'agent_price'    => number_format((float)($pricing['price_agent'] ?? 39.00), 2, '.', ''),
                        'price_text'     => $isFree ? '免费 · 从插件商城下载安装' : '¥ ' . number_format($priceVal, 2) . ' / 永久授权',
                        'is_free'        => $isFree,
                        'entitled'       => false, // 降级模式下无法确认云端授权状态，一律返回 false
                        'status'         => $p['status'] ?? 'ACTIVE',
                        'manifest'       => $manifest,
                        'requires'       => $manifest['requires'] ?? [],
                        'permissions'    => $manifest['permissions'] ?? [],
                    ];
                }
                return [
                    'code' => 1,
                    'msg'  => 'ok',
                    'data' => [
                        'plugins' => $plugins,
                        'list'    => $plugins,
                        'total'   => count($plugins),
                    ],
                ];
            }
        } catch (\Throwable) {
            // 离线降级
        }

        return ['code' => -1, 'msg' => '云端目录拉取失败', 'data' => ['plugins' => []]];
    }

    /**
     * 检查指定插件的更新
     */
    public function checkUpdates(string $pluginId, string $currentVersion, string $cxpayVersion = '1.0.0'): array
    {
        return $this->signedRequest('GET', "/api/instance/v1/plugins/{$pluginId}/updates", [
            'current_version' => $currentVersion,
            'cxpay_version' => $cxpayVersion,
        ]);
    }

    /**
     * 从云端申请凭证、下载并安装插件
     */
    public function downloadAndInstallPlugin(string $pluginId, string $version, string $cxpayVersion = '1.0.0'): array
    {
        $downloadUrl = '';
        $expectedSha256 = '';

        // 1. 尝试申请官方一次性下载凭证
        try {
            $grantResponse = $this->signedRequest('POST', "/api/instance/v1/plugins/{$pluginId}/download-grants", [], [
                'version' => $version,
                'cxpay_version' => $cxpayVersion,
            ]);

            $grantData = $grantResponse['data'] ?? [];
            $grantToken = (string)($grantData['grant_token'] ?? '');
            $expectedSha256 = (string)($grantData['sha256'] ?? '');

            if ($grantToken !== '') {
                $downloadUrl = $this->cloudApiUrl . "/api/artifact/v1/downloads/{$grantToken}";
            }
        } catch (\Throwable $grantErr) {
            // 实例尚未绑定或云端签名通信异常时，尝试从官方签名分发源直接拉取基础插件包
            $downloadUrl = $this->cloudApiUrl . "/downloads/plugins/{$pluginId}.cxpay-plugin";
        }

        if ($downloadUrl === '') {
            $downloadUrl = $this->cloudApiUrl . "/downloads/plugins/{$pluginId}.cxpay-plugin";
        }

        // 2. 兑换并下载数字签名交付包
        $tempFile = runtime_path() . '/plugin_temp/' . $pluginId . '_' . bin2hex(random_bytes(6)) . '.cxpay-plugin';
        @mkdir(dirname($tempFile), 0755, true);

        try {
            $this->downloadFile($downloadUrl, $tempFile);

            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                // 若标准分发包失败，再尝试备用公共下载路由
                $fallbackUrl = $this->cloudApiUrl . "/downloads/plugins/" . $pluginId . ".cxpay-plugin";
                if ($downloadUrl !== $fallbackUrl) {
                    $this->downloadFile($fallbackUrl, $tempFile);
                }
            }

            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                throw new RuntimeException('插件包下载失败或分发源文件不存在');
            }

            if ($expectedSha256 !== '' && !hash_equals(strtolower($expectedSha256), strtolower(hash_file('sha256', $tempFile)))) {
                throw new RuntimeException('插件包 SHA-256 校验和不匹配，包已被篡改或下载不完整');
            }

            // 3. 调用本地安装器完成解压、验签与注册
            $installer = new PluginPackageInstaller(
                (string)config('payment_plugin.path', base_path() . '/plugin/cxpay'),
                (string)config('payment_plugin.trusted_keys', base_path() . '/config/plugin_keys'),
                PluginManager::registry(),
                (int)config('payment_plugin.max_package_size', 20 * 1024 * 1024),
                (int)config('payment_plugin.max_file_size', 5 * 1024 * 1024),
                (int)config('payment_plugin.max_files', 500)
            );

            $pluginEntry = $installer->install($tempFile);
            PaymentManager::flush();

            return [
                'plugin_id' => $pluginId,
                'version' => $version,
                'name' => $pluginEntry['name'] ?? $pluginId,
                'status' => 'installed',
            ];
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }



    /**
     * 执行带 Ed25519 规范串签名的实例受保护请求
     */
    public function signedRequest(string $method, string $path, array $queryParams = [], array $body = []): array
    {
        $identity = $this->getIdentity();
        if (!$identity['activated'] || empty($identity['instance_id'])) {
            throw new RuntimeException('当前 CXPAY 实例尚未激活，请先完成实例绑定');
        }

        $method = strtoupper(trim($method));
        $instanceId = (string)$identity['instance_id'];
        $timestamp = time();
        $nonce = self::base64UrlEncode(random_bytes(16));

        // 构造请求路径与排序查询串
        $fullPath = $path;
        if (!empty($queryParams)) {
            ksort($queryParams);
            $queryPairs = [];
            foreach ($queryParams as $k => $v) {
                $queryPairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
            }
            $fullPath .= '?' . implode('&', $queryPairs);
        }

        $rawBody = !empty($body) ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';

        // 计算 6 行规范串签名
        $canonical = self::buildCanonicalString(
            httpMethod: $method,
            requestPath: $fullPath,
            timestamp: $timestamp,
            nonce: $nonce,
            rawBody: $rawBody,
            instanceId: $instanceId
        );

        $secretKeyBytes = self::base64UrlDecode($identity['secret_key']);
        $signatureBytes = self::signDetached($canonical, $secretKeyBytes);
        $signatureBase64 = self::base64UrlEncode($signatureBytes);

        $headers = [
            'X-CXPAY-Instance' => $instanceId,
            'X-CXPAY-Timestamp' => (string)$timestamp,
            'X-CXPAY-Nonce' => $nonce,
            'X-CXPAY-Signature' => $signatureBase64,
            'Accept' => 'application/json',
        ];
        if (!empty($body)) {
            $headers['Content-Type'] = 'application/json';
            $headers['Idempotency-Key'] = 'idemp_' . bin2hex(random_bytes(12));
        }

        $url = $this->cloudApiUrl . $fullPath;
        if ($method === 'GET') {
            return $this->httpGet($url, $headers);
        }

        return $this->httpPost($url, $body, $headers);
    }

    public static function buildCanonicalString(
        string $httpMethod,
        string $requestPath,
        int|string $timestamp,
        string $nonce,
        string $rawBody,
        string $instanceId
    ): string {
        $method = strtoupper(trim($httpMethod));
        $normalizedPath = self::normalizePath($requestPath);
        $ts = (string)$timestamp;
        $bodySha256 = hash('sha256', $rawBody);

        return implode("\n", [
            $method,
            $normalizedPath,
            $ts,
            trim($nonce),
            $bodySha256,
            trim($instanceId),
        ]);
    }

    public static function normalizePath(string $rawUri): string
    {
        $parsed = parse_url($rawUri);
        $path = $parsed['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        $query = $parsed['query'] ?? '';
        if ($query === '') {
            return $path;
        }

        parse_str($query, $params);
        ksort($params);
        $encodedPairs = [];
        foreach ($params as $k => $v) {
            $encodedPairs[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        }

        return $path . '?' . implode('&', $encodedPairs);
    }

    public static function canonicalizeDomain(string $domain): string
    {
        $d = trim($domain);
        if (preg_match('#^https?://#i', $d)) {
            $parsed = parse_url($d, PHP_URL_HOST);
            $d = $parsed !== null && $parsed !== false ? (string)$parsed : $d;
        }
        $d = rtrim($d, '/');
        if (str_contains($d, ':')) {
            $d = explode(':', $d)[0];
        }
        $d = rtrim($d, '.');
        $d = strtolower($d);

        if (function_exists('idn_to_ascii')) {
            $converted = idn_to_ascii($d, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($converted !== false && $converted !== '') {
                $d = strtolower($converted);
            }
        }

        return $d;
    }

    public static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $encoded): string
    {
        $remainder = strlen($encoded) % 4;
        $padded = strtr($encoded, '-_', '+/') . str_repeat('=', (4 - $remainder) % 4);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('无效的 Base64URL 字符串');
        }
        return $decoded;
    }

    private function httpGet(string $url, array $headers = []): array
    {
        if ($this->httpClient !== null) {
            try {
                $res = $this->httpClient->request('GET', $url, ['headers' => $headers, 'timeout' => 10]);
                return json_decode((string)$res->getBody(), true) ?: [];
            } catch (GuzzleException $e) {
                throw new RuntimeException('云端请求网络异常: ' . $e->getMessage());
            }
        }

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => $this->formatHeaders($headers),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ];
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) {
            throw new RuntimeException('无法连接到云端服务: ' . $url);
        }
        return json_decode($raw, true) ?: [];
    }

    private function httpPost(string $url, array $data, array $headers = []): array
    {
        $rawBody = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($this->httpClient !== null) {
            try {
                $res = $this->httpClient->request('POST', $url, [
                    'headers' => $headers,
                    'body' => $rawBody,
                    'timeout' => 10,
                ]);
                return json_decode((string)$res->getBody(), true) ?: [];
            } catch (GuzzleException $e) {
                throw new RuntimeException('云端请求网络异常: ' . $e->getMessage());
            }
        }

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => $this->formatHeaders($headers),
                'content' => $rawBody,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ];
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) {
            throw new RuntimeException('无法连接到云端服务: ' . $url);
        }
        return json_decode($raw, true) ?: [];
    }

    private function downloadFile(string $url, string $targetFile): void
    {
        if ($this->httpClient !== null) {
            try {
                $this->httpClient->request('GET', $url, ['sink' => $targetFile, 'timeout' => 30]);
                return;
            } catch (GuzzleException $e) {
                throw new RuntimeException('插件包下载异常: ' . $e->getMessage());
            }
        }

        $opts = [
            'http' => ['method' => 'GET', 'timeout' => 30, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ];
        $in = @fopen($url, 'rb', false, stream_context_create($opts));
        if (!$in) {
            throw new RuntimeException('无法下载插件包');
        }
        $out = fopen($targetFile, 'wb');
        while (!feof($in)) {
            fwrite($out, fread($in, 8192));
        }
        fclose($in);
        fclose($out);
    }

    public static function generateKeyPair(): array
    {
        if (function_exists('sodium_crypto_sign_keypair')) {
            $keyPair = sodium_crypto_sign_keypair();
            return [
                'secretKey' => sodium_crypto_sign_secretkey($keyPair),
                'publicKey' => sodium_crypto_sign_publickey($keyPair),
            ];
        }

        if (class_exists(\ParagonIE_Sodium_Compat::class)) {
            $keyPair = \ParagonIE_Sodium_Compat::crypto_sign_keypair();
            return [
                'secretKey' => \ParagonIE_Sodium_Compat::crypto_sign_secretkey($keyPair),
                'publicKey' => \ParagonIE_Sodium_Compat::crypto_sign_publickey($keyPair),
            ];
        }

        throw new RuntimeException('未检测到 Sodium 扩展或 Sodium 兼容库');
    }

    public static function signDetached(string $message, string $secretKey): string
    {
        if (function_exists('sodium_crypto_sign_detached')) {
            return sodium_crypto_sign_detached($message, $secretKey);
        }

        if (class_exists(\ParagonIE_Sodium_Compat::class)) {
            return \ParagonIE_Sodium_Compat::crypto_sign_detached($message, $secretKey);
        }

        throw new RuntimeException('未检测到 Sodium 扩展或 Sodium 兼容库');
    }

    private function formatHeaders(array $headers): string
    {
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        return implode("\r\n", $lines);
    }
}

