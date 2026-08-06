<?php

declare(strict_types=1);

namespace AlipayAutoConfig;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * 支付宝开放平台 API 客户端。
 *
 * 仅实现自动配置验证所需的最小接口集：
 * - alipay.data.bill.accountlog.query（验证账单查询权限）
 *
 * 所有请求使用 RSA2 (SHA256WithRSA) 签名。
 */
final class AlipayApiClient
{
    private const GATEWAY = 'https://openapi.alipay.com/gateway.do';

    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout'         => 10.0,
            'connect_timeout' => 5.0,
            'verify'          => true,
            'http_errors'     => false,
        ]);
    }

    /**
     * 验证商户填写的 AppID + 私钥 + 支付宝公钥组合是否可用。
     *
     * 调用账单查询接口（时间窗口为最近 1 分钟），检测：
     * 1. 私钥能正常签名
     * 2. AppID 对应的应用存在且已上线
     * 3. 支付宝公钥能正常验签
     * 4. 应用具备账单查询权限
     *
     * @return array{ok:bool, message:string, alipay_public_key?:string}
     */
    public function verifyAppCredentials(
        string $appId,
        string $appPrivateKey,
        string $alipayPublicKey
    ): array {
        $now    = time();
        $params = [
            'app_id'      => $appId,
            'method'      => 'alipay.data.bill.accountlog.query',
            'format'      => 'JSON',
            'charset'     => 'utf-8',
            'sign_type'   => 'RSA2',
            'timestamp'   => date('Y-m-d H:i:s', $now),
            'version'     => '1.0',
            'biz_content' => json_encode([
                'start_time' => date('Y-m-d H:i:s', $now - 60),
                'end_time'   => date('Y-m-d H:i:s', $now),
            ], JSON_THROW_ON_ERROR),
        ];

        try {
            $params['sign'] = $this->sign($params, $appPrivateKey);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => '私钥无效：' . $e->getMessage()];
        }

        $response = $this->http->request('POST', self::GATEWAY, [
            'form_params' => $params,
            'headers'     => ['Accept' => 'application/json'],
        ]);

        if ($response->getStatusCode() !== 200) {
            return ['ok' => false, 'message' => '支付宝网关 HTTP ' . $response->getStatusCode()];
        }

        $raw     = (string)$response->getBody();
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $node    = $decoded['alipay_data_bill_accountlog_query_response'] ?? null;

        if (!is_array($node)) {
            return ['ok' => false, 'message' => '支付宝响应结构异常'];
        }

        // 验签
        try {
            $this->verifyResponse($raw, 'alipay_data_bill_accountlog_query_response',
                (string)($decoded['sign'] ?? ''), $alipayPublicKey);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => '支付宝公钥验签失败：' . $e->getMessage()];
        }

        $code = (string)($node['code'] ?? '');

        // 10000 = 成功；40006 = 权限不足但签名和 AppID 正确
        if ($code === '10000' || $code === '40006') {
            $msg = $code === '40006'
                ? '警告：账单查询权限尚未开通，请在开放平台申请"账户流水"权限后再启用通道'
                : '配置验证通过，账单查询权限正常';
            return ['ok' => true, 'message' => $msg];
        }

        // 40001 = 无效 AppID；20001 = 授权错误（签名/公钥问题）
        $message = trim((string)($node['sub_msg'] ?? $node['msg'] ?? '未知错误'));
        return ['ok' => false, 'message' => "支付宝返回错误 [{$code}]：{$message}"];
    }

    // ─── 签名与验签 ────────────────────────────────────────────────────────────

    /** @param array<string, string> $params */
    private function sign(array $params, string $privateKey): string
    {
        unset($params['sign']);
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== '') {
                $pairs[] = $key . '=' . $value;
            }
        }
        $canonical = implode('&', $pairs);
        $signature = '';
        if (!openssl_sign($canonical, $signature, $this->wrapPrivateKey($privateKey), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('应用私钥签名失败，请检查私钥格式');
        }
        return base64_encode($signature);
    }

    private function verifyResponse(string $json, string $nodeName, string $signature, string $publicKey): void
    {
        if ($signature === '') {
            throw new RuntimeException('响应缺少签名');
        }
        $rawNode = $this->extractJsonNode($json, $nodeName);
        $decoded = base64_decode($signature, true);
        if ($decoded === false
            || openssl_verify($rawNode, $decoded, $this->wrapPublicKey($publicKey), OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('验签失败，请检查支付宝公钥是否正确');
        }
    }

    /** 从 JSON 字符串中提取指定 key 对应的原始 JSON 子字符串（用于验签）。 */
    private function extractJsonNode(string $json, string $key): string
    {
        if (!preg_match('/"' . preg_quote($key, '/') . '"\s*:\s*/', $json, $match, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('响应节点缺失');
        }
        $start = $match[0][1] + strlen($match[0][0]);
        if (($json[$start] ?? '') !== '{') {
            throw new RuntimeException('响应节点格式异常');
        }
        $depth = 0; $quoted = false; $escaped = false;
        for ($i = $start, $len = strlen($json); $i < $len; $i++) {
            $c = $json[$i];
            if ($quoted) {
                if ($escaped) { $escaped = false; }
                elseif ($c === '\\') { $escaped = true; }
                elseif ($c === '"') { $quoted = false; }
                continue;
            }
            if ($c === '"') { $quoted = true; }
            elseif ($c === '{') { $depth++; }
            elseif ($c === '}' && --$depth === 0) {
                return substr($json, $start, $i - $start + 1);
            }
        }
        throw new RuntimeException('响应 JSON 不完整');
    }

    private function wrapPrivateKey(string $key): string
    {
        return $this->wrapKey($key, 'PRIVATE KEY');
    }

    private function wrapPublicKey(string $key): string
    {
        return $this->wrapKey($key, 'PUBLIC KEY');
    }

    private function wrapKey(string $key, string $type): string
    {
        $key = trim($key);
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }
        $key = preg_replace('/\s+/', '', $key) ?? '';
        return "-----BEGIN {$type}-----\n" . chunk_split($key, 64, "\n") . "-----END {$type}-----";
    }
}
