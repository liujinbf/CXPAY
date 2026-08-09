<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\admin\AdminAuthController;
use app\middleware\AdminAuthMiddleware;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Webman\Route;
use Webman\Route\Route as RouteObject;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminControllerRouteContractTest extends TestCase
{
    protected function setUp(): void
    {
        Route::load([dirname(__DIR__, 2) . '/config']);
    }

    public function testPublicAuthenticationRoutesUseDedicatedController(): void
    {
        $this->assertRoute('POST', '/api/admin/login', [AdminAuthController::class, 'login']);
        $this->assertRoute('POST', '/api/admin/login/verify', [AdminAuthController::class, 'verifyLoginCode']);
        $this->assertRoute('POST', '/api/admin/logout', [AdminAuthController::class, 'logout']);
    }

    public function testDashboardRouteUsesDedicatedController(): void
    {
        $route = $this->assertRoute(
            'GET',
            '/api/admin/dashboard',
            [\app\controller\admin\AdminDashboardController::class, 'dashboard'],
            $this->adminMiddleware()
        );
        self::assertSame(
            ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'],
            $route->getMethods()
        );
    }

    public function testPlatformChannelRoutesUseConfigController(): void
    {
        $class = \app\controller\admin\AdminChannelConfigController::class;
        $middleware = $this->adminMiddleware();
        $routes = [
            ['GET', '/api/admin/channel/list', [$class, 'listChannels']],
            ['POST', '/api/admin/channel/save', [$class, 'saveChannelConfig']],
            ['GET', '/api/admin/channel/get', [$class, 'getChannelConfig']],
            ['POST', '/api/admin/channel/config/save', [$class, 'saveChannelConfig']],
            ['GET', '/api/admin/channel/inputs', [\app\controller\admin\ChannelAdminController::class, 'getConfigInputs']],
        ];

        foreach ($routes as [$method, $path, $callback]) {
            $this->assertRoute($method, $path, $callback, $middleware);
        }
    }

    public function testMerchantRoutesUseDedicatedController(): void
    {
        $class = \app\controller\admin\AdminMerchantController::class;
        $middleware = $this->adminMiddleware();
        $this->assertRoute('GET', '/api/admin/merchant/list', [$class, 'listMerchants'], $middleware);
        $this->assertRoute('POST', '/api/admin/merchant/save', [$class, 'saveMerchant'], $middleware);
    }

    private function assertRoute(
        string $method,
        string $path,
        array $callback,
        array $middleware = []
    ): RouteObject {
        $route = $this->route($method, $path);
        self::assertSame($callback, $route->getCallback());
        self::assertSame($middleware, $route->getMiddleware());

        return $route;
    }

    private function route(string $method, string $path): RouteObject
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->getPath() === $path && in_array($method, $route->getMethods(), true)) {
                return $route;
            }
        }

        self::fail("未注册路由 {$method} {$path}");
    }

    private function adminMiddleware(): array
    {
        return [AdminAuthMiddleware::class];
    }
}
