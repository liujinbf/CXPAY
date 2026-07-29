<?php

declare(strict_types=1);

namespace plugin\cxpay\alipay_accountlog_monitor;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

final class AccountLogClient
{
    private const GATEWAY = 'https://openapi.alipay.com/gateway.do';

    public function __construct(private readonly ?ClientInterface $http = null)
    {
    }

    /** @return list<array{source_bill_id:string,amount:string,occurred_at:int,remark:string,raw_hash:string}> */
    public function query(string $appId, string $privateKey, string $alipayPublicKey, int $since, int $until): array
    {
        if ($since <= 0 || $until < $since || $until - $since > 600) {
            throw new \RuntimeException('支付宝账单查询时间窗口不合法');
        }
        $params = [
            'app_id' => $appId,
            'method' => 'alipay.data.bill.accountlog.query',
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'biz_content' => json_encode([
                'start_time' => date('Y-m-d H:i:s', $since),
                'end_time' => date('Y-m-d H:i:s', $until),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
        $params['sign'] = $this->sign($params, $privateKey);

        $client = $this->http ?? new Client(['timeout' => 10, 'connect_timeout' => 5, 'verify' => true]);
        $response = $client->request('POST', self::GATEWAY, [
            'form_params' => $params,
            'headers' => ['Accept' => 'application/json'],
            'http_errors' => false,
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('支付宝账单接口 HTTP 状态异常');
        }
        $raw = (string)$response->getBody();
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $nodeName = 'alipay_data_bill_accountlog_query_response';
        $node = $decoded[$nodeName] ?? null;
        if (!is_array($node)) {
            throw new \RuntimeException('支付宝账单接口响应结构异常');
        }
        $this->verifyResponse($raw, $nodeName, (string)($decoded['sign'] ?? ''), $alipayPublicKey);
        if ((string)($node['code'] ?? '') !== '10000') {
            $message = trim((string)($node['sub_msg'] ?? $node['msg'] ?? '未知错误'));
            throw new \RuntimeException('支付宝账单查询失败：' . $message);
        }

        $events = [];
        foreach ((array)($node['detail_list'] ?? []) as $detail) {
            if (!is_array($detail)
                || (string)($detail['direction'] ?? $detail['trans_direction'] ?? '') !== '收入') {
                continue;
            }
            $billId = trim((string)($detail['alipay_order_no'] ?? ''));
            $amount = str_replace(',', '', trim((string)($detail['trans_amount'] ?? '')));
            $occurredAt = strtotime((string)($detail['trans_dt'] ?? '')) ?: 0;
            if (!preg_match('/^[A-Za-z0-9_.:-]{8,128}$/', $billId)
                || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)
                || (float)$amount <= 0 || $occurredAt <= 0) {
                continue;
            }
            $events[] = [
                'source_bill_id' => $billId,
                'amount' => number_format((float)$amount, 2, '.', ''),
                'occurred_at' => $occurredAt,
                'remark' => trim((string)($detail['trans_memo'] ?? $detail['other_account'] ?? '')),
                'raw_hash' => hash('sha256', json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ];
            if (count($events) >= 100) {
                break;
            }
        }
        return $events;
    }

    /** @param array<string,string> $params */
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
        if (!openssl_sign($canonical, $signature, self::privateKey($privateKey), OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('支付宝应用私钥签名失败');
        }
        return base64_encode($signature);
    }

    private function verifyResponse(string $json, string $nodeName, string $signature, string $publicKey): void
    {
        if ($signature === '') {
            throw new \RuntimeException('支付宝响应缺少签名');
        }
        $rawNode = $this->extractRawJsonValue($json, $nodeName);
        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false
            || openssl_verify($rawNode, $decodedSignature, self::publicKey($publicKey), OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('支付宝账单响应验签失败');
        }
    }

    private function extractRawJsonValue(string $json, string $key): string
    {
        if (!preg_match('/"' . preg_quote($key, '/') . '"\s*:\s*/', $json, $match, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException('支付宝响应节点缺失');
        }
        $start = $match[0][1] + strlen($match[0][0]);
        if (($json[$start] ?? '') !== '{') {
            throw new \RuntimeException('支付宝响应节点格式异常');
        }
        $depth = 0;
        $quoted = false;
        $escaped = false;
        for ($i = $start, $length = strlen($json); $i < $length; $i++) {
            $char = $json[$i];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $quoted = false;
                }
                continue;
            }
            if ($char === '"') {
                $quoted = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return substr($json, $start, $i - $start + 1);
            }
        }
        throw new \RuntimeException('支付宝响应 JSON 不完整');
    }

    public static function privateKey(string $key): string
    {
        return self::wrapKey($key, 'PRIVATE KEY');
    }

    public static function publicKey(string $key): string
    {
        return self::wrapKey($key, 'PUBLIC KEY');
    }

    private static function wrapKey(string $key, string $type): string
    {
        $key = trim($key);
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }
        $key = preg_replace('/\s+/', '', $key) ?? '';
        return "-----BEGIN {$type}-----\n" . chunk_split($key, 64, "\n") . "-----END {$type}-----";
    }
}
