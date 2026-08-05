<?php

declare(strict_types=1);

namespace app\payment;

use Closure;

final class EpayUpstreamGuard
{
    public const REJECTED_MESSAGE =
        '外部易支付上游不能指向当前 CXPAY 实例';

    /**
     * @var Closure(string):list<string>
     */
    private Closure $resolver;

    /**
     * @param null|Closure(string):list<string> $resolver
     */
    public function __construct(
        ?Closure $resolver = null,
        private readonly ?string $appUrl = null,
        private readonly ?string $serviceHost = null,
        private readonly ?int $servicePort = null
    ) {
        $this->resolver = $resolver
            ?? static fn(string $host): array =>
                self::resolveDns($host);
    }

    /**
     * @return array{
     *     scheme:string,
     *     host:string,
     *     port:int,
     *     ip:string
     * }
     */
    public function validate(string $apiUrl): array
    {
        $target = $this->parseEndpoint($apiUrl);
        $current = $this->parseEndpoint(
            $this->currentAppUrl()
        );

        if ($target['host'] === $current['host']) {
            $this->reject();
        }

        $targetIps = $this->publicAddresses(
            $target['host']
        );
        $currentIps = $this->publicAddresses(
            $current['host']
        );

        if (
            $target['port'] === $current['port']
            && array_intersect(
                $targetIps,
                $currentIps
            ) !== []
        ) {
            $this->reject();
        }

        $this->assertNotServiceEndpoint(
            $target['host'],
            $target['port'],
            $targetIps
        );

        return [
            'scheme' => $target['scheme'],
            'host' => $target['host'],
            'port' => $target['port'],
            'ip' => $targetIps[0],
        ];
    }

    /**
     * @return array{
     *     scheme:string,
     *     host:string,
     *     port:int
     * }
     */
    private function parseEndpoint(string $url): array
    {
        $parts = parse_url(trim($url));

        if (
            !is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            $this->reject();
        }

        $scheme = strtolower(
            (string)($parts['scheme'] ?? '')
        );
        $host = rtrim(
            strtolower(
                (string)($parts['host'] ?? '')
            ),
            '.'
        );

        if (
            !in_array(
                $scheme,
                ['http', 'https'],
                true
            )
            || $host === ''
            || $host === 'localhost'
        ) {
            $this->reject();
        }

        $port = (int)(
            $parts['port']
            ?? ($scheme === 'https' ? 443 : 80)
        );

        if (!in_array($port, [80, 443], true)) {
            $this->reject();
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
        ];
    }

    private function currentAppUrl(): string
    {
        return $this->appUrl
            ?? (string)config('app.url', '');
    }

    private function currentServiceHost(): string
    {
        return rtrim(
            strtolower(
                $this->serviceHost
                ?? (string)env(
                    'HOST',
                    '127.0.0.1'
                )
            ),
            '.'
        );
    }

    private function currentServicePort(): int
    {
        return $this->servicePort
            ?? (int)env('PORT', 8787);
    }

    /**
     * @return list<string>
     */
    private function publicAddresses(
        string $host
    ): array {
        $addresses = filter_var(
            $host,
            FILTER_VALIDATE_IP
        )
            ? [$host]
            : ($this->resolver)($host);

        $normalized = [];

        foreach ($addresses as $address) {
            $packed = @inet_pton(
                (string)$address
            );

            if ($packed === false) {
                $this->reject();
            }

            $ip = inet_ntop($packed);

            if (
                !is_string($ip)
                || !$this->isPublicIp($ip)
            ) {
                $this->reject();
            }

            $normalized[] = strtolower($ip);
        }

        $normalized = array_values(
            array_unique($normalized)
        );

        if ($normalized === []) {
            $this->reject();
        }

        return $normalized;
    }

    /**
     * @param list<string> $targetIps
     */
    private function assertNotServiceEndpoint(
        string $targetHost,
        int $targetPort,
        array $targetIps
    ): void {
        $serviceHost = $this->currentServiceHost();
        $servicePort = $this->currentServicePort();

        if ($targetPort !== $servicePort) {
            return;
        }

        if (
            $serviceHost !== ''
            && $targetHost === $serviceHost
        ) {
            $this->reject();
        }

        if (filter_var(
            $serviceHost,
            FILTER_VALIDATE_IP
        )) {
            $packed = @inet_pton($serviceHost);
            $normalized = $packed === false
                ? false
                : inet_ntop($packed);

            if (
                is_string($normalized)
                && in_array(
                    strtolower($normalized),
                    $targetIps,
                    true
                )
            ) {
                $this->reject();
            }
        }
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_GLOBAL_RANGE
        ) !== false;
    }

    /**
     * @return list<string>
     */
    private static function resolveDns(
        string $host
    ): array {
        $addresses = [];

        foreach (
            dns_get_record(
                $host,
                DNS_A | DNS_AAAA
            ) ?: []
            as $record
        ) {
            $ip = (string)(
                $record['ip']
                ?? $record['ipv6']
                ?? ''
            );

            if ($ip !== '') {
                $addresses[] = $ip;
            }
        }

        return array_values(
            array_unique($addresses)
        );
    }

    private function reject(): never
    {
        throw new EpayUpstreamException(
            self::REJECTED_MESSAGE
        );
    }
}
