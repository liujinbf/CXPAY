<?php

declare(strict_types=1);

namespace WxpayClerk;

use Closure;
use RuntimeException;

final class PublicHttpsUrlGuard
{
    private Closure $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver !== null
            ? Closure::fromCallable($resolver)
            : static function (string $host): array {
                $addresses = [];
                foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                    $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                    if ($address !== '') {
                        $addresses[] = $address;
                    }
                }
                return $addresses;
            };
    }

    /** @return array{host: string, port: int, ip: string} */
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new RuntimeException('回调地址必须是无用户信息的公网 HTTPS URL');
        }
        $port = (int) ($parts['port'] ?? 443);
        if ($port !== 443) {
            throw new RuntimeException('回调地址只允许 HTTPS 标准端口 443');
        }

        $host = trim(rtrim(strtolower((string) $parts['host']), '.'), '[]');
        if ($host === '' || $host === 'localhost') {
            throw new RuntimeException('回调地址主机不合法');
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_values(array_unique(($this->resolver)($host)));
        if ($addresses === []) {
            throw new RuntimeException('回调地址无法解析');
        }
        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                throw new RuntimeException('回调地址解析到非公网 IP');
            }
        }
        return ['host' => $host, 'port' => $port, 'ip' => (string) $addresses[0]];
    }

    public function assertAllowed(string $url): void
    {
        $this->resolve($url);
    }
}
