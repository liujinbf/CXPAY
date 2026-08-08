<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\admin\PluginMarketController;
use PHPUnit\Framework\TestCase;
use support\Request;

final class CloudPluginMarketBoundaryTest extends TestCase
{
    public function testCatalogRequiresActivatedInstanceProtocol(): void
    {
        $response = (new PluginMarketController())->getCloudMarket();
        $payload = json_decode($response->rawBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('CLOUD_INSTANCE_ACTIVATION_REQUIRED', $payload['error_code']);
        self::assertSame('ACTIVATE_INSTANCE', $payload['data']['action']);
    }

    public function testPurchaseMovesToIndependentCloudPortal(): void
    {
        $response = (new PluginMarketController())->buyFromCloud($this->request());
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
        $response = (new PluginMarketController())->downloadFromCloud($this->request());
        $body = $response->rawBody();
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('CLOUD_INSTANCE_ACTIVATION_REQUIRED', $payload['error_code']);
        self::assertStringNotContainsString('auth_key', $body);
        self::assertStringNotContainsString('download_url', $body);
    }

    private function request(): Request
    {
        return new Request("POST / HTTP/1.1\r\nHost: pay.example.com\r\nContent-Length: 0\r\n\r\n");
    }
}
