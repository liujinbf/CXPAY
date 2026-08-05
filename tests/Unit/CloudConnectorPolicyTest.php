<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\payment\Plugin\CloudConnectorPolicy;
use app\payment\Plugin\PluginException;
use app\payment\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

final class CloudConnectorPolicyTest extends TestCase
{
    public function testManifestExposesCloudConnectorSecurityFields(): void
    {
        $manifest = $this->cloudManifest();

        self::assertSame('cloud_connector', $manifest->runtimeType());
        self::assertSame('cloud_only', $manifest->credentialBoundary());
        self::assertSame('cxpay-cloud-payment-v1', $manifest->cloudProtocol());
        self::assertSame(['api.provider.example'], $manifest->permissions()['outbound_hosts']);
        self::assertSame(['external_monitor' => true], $manifest->capabilities());
    }

    public function testCloudOnlyManifestRejectsForbiddenSecretNames(): void
    {
        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('Cookie');

        $manifest = $this->cloudManifest([
            'secret_config' => ['client_secret', 'cookie_base64'],
        ]);

        (new CloudConnectorPolicy())->assertManifest($manifest);
    }

    public function testCloudOnlyDriverMetaRejectsCookieInput(): void
    {
        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('Cookie');

        (new CloudConnectorPolicy())->assertDriverMeta($this->cloudManifest(), [
            'inputs' => [[
                'name' => 'cookie_base64',
                'title' => 'Cookie',
                'type' => 'textarea',
            ]],
        ]);
    }

    /** @param array<string, mixed> $permissionOverrides */
    private function cloudManifest(array $permissionOverrides = []): PluginManifest
    {
        $permissions = array_replace([
            'outbound_hosts' => ['api.provider.example'],
            'callbacks' => ['/notify/alipay_demo_connector'],
            'scheduled_tasks' => false,
            'secret_config' => ['client_secret', 'callback_secret'],
        ], $permissionOverrides);

        return PluginManifest::fromJson(json_encode([
            'schema' => 1,
            'id' => 'cxpay.alipay.demo_connector',
            'slug' => 'alipay_demo_connector',
            'name' => '支付宝测试连接器',
            'version' => '1.0.0',
            'publisher' => 'cxpay.official',
            'payment_type' => 'alipay',
            'collection_mode' => 'personal_qr',
            'monitor_mode' => 'callback',
            'runtime_type' => 'cloud_connector',
            'credential_boundary' => 'cloud_only',
            'cloud_protocol' => 'cxpay-cloud-payment-v1',
            'capabilities' => ['external_monitor' => true],
            'permissions' => $permissions,
            'drivers' => [[
                'code' => 'alipay_demo_connector',
                'class' => 'plugin\\cxpay\\alipay_demo_connector\\Driver',
                'file' => 'src/Driver.php',
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
