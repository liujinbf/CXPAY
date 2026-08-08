<?php

declare(strict_types=1);

namespace WxpayClerk;

use RuntimeException;

final class CurlCallbackTransport implements CallbackTransportInterface
{
    public function __construct(private readonly PublicHttpsUrlGuard $urlGuard)
    {
    }

    public function post(string $url, array $fields): array
    {
        $target = $this->urlGuard->resolve($url);
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('无法初始化回调 HTTP 客户端');
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"],
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $body = curl_exec($handle);
            if ($body === false) {
                throw new RuntimeException('回调连接失败: ' . curl_error($handle));
            }
            return [
                'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
                'body' => (string) $body,
            ];
        } finally {
            curl_close($handle);
        }
    }
}
