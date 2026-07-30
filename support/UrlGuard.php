<?php

declare(strict_types=1);

namespace support;

/**
 * 服务端出站 HTTP 地址校验，默认阻止内网、环回与保留地址。
 */
final class UrlGuard
{
    /**
     * @return array{host:string,port:int,ip:string}|null
     */
    public static function resolve(string $url, bool $allowPrivate = false): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string)$parts['scheme']);
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        // 生产模式仅允许标准 HTTP/HTTPS 端口（80/443），防止 SSRF 探测内部服务。
        // 开启 allow_private_callbacks 时（通常用于内网/staging 环境）不限制端口。
        if (!$allowPrivate && !in_array($port, [80, 443], true)) {
            return null;
        }

        $host = rtrim(strtolower((string)$parts['host']), '.');
        if ($host === '' || $host === 'localhost') {
            return null;
        }

        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } elseif (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/', $host)) {
            foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $ip = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') {
                    $addresses[] = $ip;
                }
            }
        }

        foreach (array_unique($addresses) as $ip) {
            if ($allowPrivate || self::isPublicIp($ip)) {
                return ['host' => $host, 'port' => $port, 'ip' => $ip];
            }
        }
        return null;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
