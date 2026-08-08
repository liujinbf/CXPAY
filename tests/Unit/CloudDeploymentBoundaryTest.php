<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\api\CloudLicenseController;
use PHPUnit\Framework\TestCase;
use Webman\Config;
use Webman\Route;

final class CloudDeploymentBoundaryTest extends TestCase
{
    /** @var list<string> */
    private static array $paths = [];

    public static function setUpBeforeClass(): void
    {
        Config::load(config_path(), ['route']);
        Route::load([base_path() . '/config']);
        self::$paths = array_values(array_map(
            static fn($route): string => $route->getPath(),
            Route::getRoutes()
        ));
    }

    public function testPaymentRuntimeDoesNotExposeEmbeddedCloudServerRoutes(): void
    {
        foreach (self::$paths as $path) {
            self::assertFalse(
                str_starts_with($path, '/api/cloud'),
                "支付节点不应注册云端服务端路由：{$path}"
            );
        }

        self::assertTrue(Route::isDefaultRouteDisabled(CloudLicenseController::class));
    }

    public function testPaymentRuntimeKeepsLocalCloudPluginClientRoutes(): void
    {
        self::assertContains('/api/admin/plugin/cloud_market', self::$paths);
        self::assertContains('/api/admin/plugin/cloud_buy', self::$paths);
        self::assertContains('/api/admin/plugin/cloud_download', self::$paths);
    }

    public function testCloudPortalUsesDedicatedConfiguration(): void
    {
        self::assertSame('payment', config('deployment.role'));
        self::assertSame('https://cloud.cxpay.com', config('cloud.portal_url'));
        self::assertSame('https://api.cloud.cxpay.com', config('cloud.api_url'));
    }
}
