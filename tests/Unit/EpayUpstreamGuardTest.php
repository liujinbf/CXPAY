<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\EpayUpstreamException;
use app\payment\EpayUpstreamGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EpayUpstreamGuardTest extends TestCase
{
    /**
     * @param array<string, list<string>> $records
     */
    private function guard(
        array $records,
        string $appUrl = 'https://pay.example.test',
        string $serviceHost = '127.0.0.1',
        int $servicePort = 8787
    ): EpayUpstreamGuard {
        $resolver = static function (string $host) use (
            $records
        ): array {
            return $records[$host] ?? [];
        };

        return new EpayUpstreamGuard(
            $resolver,
            $appUrl,
            $serviceHost,
            $servicePort
        );
    }

    public function testReturnsNormalizedValidatedTarget(): void
    {
        $guard = $this->guard([
            'pay.example.test' => ['8.8.8.8'],
            'upstream.example.test' => ['1.1.1.1'],
        ]);

        self::assertSame(
            [
                'scheme' => 'https',
                'host' => 'upstream.example.test',
                'port' => 443,
                'ip' => '1.1.1.1',
            ],
            $guard->validate(
                'HTTPS://Upstream.Example.Test./gateway'
            )
        );
    }

    public function testSameIpOnDifferentEffectivePortIsAllowed(): void
    {
        $guard = $this->guard(
            [
                'pay.example.test' => ['8.8.8.8'],
                'upstream.example.test' => ['8.8.8.8'],
            ],
            'http://pay.example.test'
        );

        self::assertSame(
            [
                'scheme' => 'https',
                'host' => 'upstream.example.test',
                'port' => 443,
                'ip' => '8.8.8.8',
            ],
            $guard->validate(
                'https://upstream.example.test/path'
            )
        );
    }

    #[DataProvider('sameHostProvider')]
    public function testRejectsSameHostAfterNormalization(
        string $url
    ): void {
        $guard = $this->guard([
            'pay.example.test' => ['8.8.8.8'],
        ]);

        $this->expectException(
            EpayUpstreamException::class
        );
        $this->expectExceptionMessage(
            EpayUpstreamGuard::REJECTED_MESSAGE
        );

        $guard->validate($url);
    }

    public static function sameHostProvider(): array
    {
        return [
            '相同域名' => [
                'https://pay.example.test',
            ],
            '大小写差异' => [
                'https://PAY.EXAMPLE.TEST/path',
            ],
            '尾点差异' => [
                'https://pay.example.test./path',
            ],
        ];
    }

    public function testRejectsDifferentHostWithSameIpAndPort(): void
    {
        $guard = $this->guard([
            'pay.example.test' => ['8.8.8.8'],
            'alias.example.test' => ['8.8.8.8'],
        ]);

        $this->expectException(
            EpayUpstreamException::class
        );
        $this->expectExceptionMessage(
            EpayUpstreamGuard::REJECTED_MESSAGE
        );

        $guard->validate(
            'https://alias.example.test'
        );
    }

    public function testRejectsDirectServiceHostAndPort(): void
    {
        $guard = $this->guard(
            [
                'pay.example.test' => ['1.1.1.1'],
            ],
            'https://pay.example.test',
            '8.8.8.8',
            443
        );

        $this->expectException(
            EpayUpstreamException::class
        );

        $guard->validate('https://8.8.8.8');
    }

    #[DataProvider('unsafeTargetProvider')]
    public function testRejectsUnsafeTarget(
        string $url,
        array $records
    ): void {
        $guard = $this->guard(
            array_merge(
                [
                    'pay.example.test' => [
                        '8.8.8.8',
                    ],
                ],
                $records
            )
        );

        $this->expectException(
            EpayUpstreamException::class
        );
        $this->expectExceptionMessage(
            EpayUpstreamGuard::REJECTED_MESSAGE
        );

        $guard->validate($url);
    }

    public static function unsafeTargetProvider(): array
    {
        return [
            'localhost' => [
                'http://localhost',
                [],
            ],
            'IPv4环回' => [
                'http://127.0.0.1',
                [],
            ],
            'IPv6环回' => [
                'http://[::1]',
                [],
            ],
            'RFC1918' => [
                'http://10.0.0.8',
                [],
            ],
            '链路本地' => [
                'http://169.254.10.20',
                [],
            ],
            '保留地址' => [
                'http://192.0.2.10',
                [],
            ],
            'URL认证信息' => [
                'https://user:pass@upstream.example.test',
                [
                    'upstream.example.test' => [
                        '1.1.1.1',
                    ],
                ],
            ],
            '非标准端口' => [
                'https://upstream.example.test:8443',
                [
                    'upstream.example.test' => [
                        '1.1.1.1',
                    ],
                ],
            ],
            'DNS无结果' => [
                'https://missing.example.test',
                [],
            ],
            'DNS混合公网私网' => [
                'https://mixed.example.test',
                [
                    'mixed.example.test' => [
                        '1.1.1.1',
                        '10.0.0.1',
                    ],
                ],
            ],
            'DNS包含无效地址' => [
                'https://invalid.example.test',
                [
                    'invalid.example.test' => [
                        '1.1.1.1',
                        'not-an-ip',
                    ],
                ],
            ],
        ];
    }

    #[DataProvider('invalidAppUrlProvider')]
    public function testFailsClosedWhenAppUrlIsMissingOrInvalid(
        string $appUrl
    ): void {
        $guard = $this->guard(
            [
                'upstream.example.test' => [
                    '1.1.1.1',
                ],
            ],
            $appUrl
        );

        $this->expectException(
            EpayUpstreamException::class
        );
        $this->expectExceptionMessage(
            EpayUpstreamGuard::REJECTED_MESSAGE
        );

        $guard->validate(
            'https://upstream.example.test'
        );
    }

    public static function invalidAppUrlProvider(): array
    {
        return [
            '空值' => [''],
            '非URL' => [
                'pay.example.test',
            ],
            '不支持协议' => [
                'ftp://pay.example.test',
            ],
            '含认证信息' => [
                'https://user:pass@pay.example.test',
            ],
            '非标准端口' => [
                'https://pay.example.test:8443',
            ],
        ];
    }

    public function testRejectsMixedCurrentAppDns(): void
    {
        $guard = $this->guard([
            'pay.example.test' => [
                '8.8.8.8',
                '10.0.0.1',
            ],
            'upstream.example.test' => [
                '1.1.1.1',
            ],
        ]);

        $this->expectException(
            EpayUpstreamException::class
        );

        $guard->validate(
            'https://upstream.example.test'
        );
    }

    public function testNormalizesIpv6Address(): void
    {
        $guard = $this->guard([
            'pay.example.test' => ['8.8.8.8'],
            'upstream.example.test' => [
                '2606:4700:4700:0:0:0:0:1111',
            ],
        ]);

        self::assertSame(
            '2606:4700:4700::1111',
            $guard->validate(
                'https://upstream.example.test'
            )['ip']
        );
    }
}
