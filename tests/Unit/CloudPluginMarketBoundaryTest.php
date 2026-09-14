<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\admin\CloudPluginMarketController;
use app\service\CloudInstanceClient;
use PHPUnit\Framework\TestCase;
use support\Request;

final class CloudPluginMarketBoundaryTest extends TestCase
{
    private string $identityFile;

    protected function setUp(): void
    {
        $this->identityFile = sys_get_temp_dir() . '/cloud_boundary_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->identityFile)) {
            @unlink($this->identityFile);
        }
    }

    public function testCatalogAllowsPublicBrowseWithoutEntitlementsWhenUnactivated(): void
    {
        $response = $this->controller()->getCloudMarket();
        $payload = json_decode($response->rawBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['code']);
        self::assertFalse($payload['data']['activated']);
        foreach ($payload['data']['plugins'] as $plugin) {
            self::assertFalse($plugin['entitled']);
        }
    }

    public function testPurchaseMovesToIndependentCloudPortal(): void
    {
        $response = $this->controller()->buyFromCloud($this->request());
        $body = $response->rawBody();
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CLOUD_PURCHASE_MOVED_TO_PORTAL', $payload['error_code']);
        self::assertSame('OPEN_PORTAL', $payload['data']['action']);
        self::assertStringEndsWith('/plugins', $payload['data']['portal_url']);
        self::assertStringNotContainsString('auth_key', $body);
    }

    public function testDownloadCannotUseLegacyDomainKeyProtocol(): void
    {
        $response = $this->controller()->downloadFromCloud($this->request('plugin_id=cxpay.driver.wechat_dy_bill'));
        $body = $response->rawBody();
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('CLOUD_INSTANCE_ACTIVATION_REQUIRED', $payload['error_code']);
        self::assertStringNotContainsString('auth_key', $body);
        self::assertStringNotContainsString('download_url', $body);
    }

    private function request(string $body = ''): Request
    {
        return new Request(
            "POST / HTTP/1.1\r\n"
            . "Host: pay.example.com\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body
        );
    }

    private function controller(): CloudPluginMarketController
    {
        return new CloudPluginMarketController(
            new CloudInstanceClient($this->identityFile, 'https://mock.cloud.cxpay.com')
        );
    }
}
