<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\api\CloudLicenseController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use support\Request;

final class LegacyCloudControllerRetirementTest extends TestCase
{
    public function testLegacyProviderActionsPointToIndependentCloudPortal(): void
    {
        $controller = new CloudLicenseController();
        $request = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();

        $actions = [
            ['getWxLoginQr', false],
            ['pollWxLogin', true],
            ['getQqLoginQr', false],
            ['pollQqLogin', true],
            ['sendEmailCode', true],
            ['bindQq', true],
            ['downloadPackage', true],
            ['traceLeaked', true],
            ['getSiteInfo', true],
            ['renewModule', true],
            ['resetKey', true],
            ['changeDomain', true],
            ['pluginMarketList', true],
            ['pluginBuy', true],
            ['pluginDownload', true],
        ];

        foreach ($actions as [$method, $needsRequest]) {
            $response = $needsRequest
                ? $controller->{$method}($request)
                : $controller->{$method}();
            $payload = json_decode($response->rawBody(), true, 512, JSON_THROW_ON_ERROR);

            self::assertSame(410, $response->getStatusCode(), "{$method} 应明确返回迁移状态");
            self::assertSame('CLOUD_CONTROL_PLANE_REQUIRED', $payload['error_code']);
            self::assertSame('OPEN_PORTAL', $payload['data']['action']);
            self::assertArrayNotHasKey('auth_key', $payload['data']);
            self::assertArrayNotHasKey('key_full', $payload['data']);
        }
    }
}
