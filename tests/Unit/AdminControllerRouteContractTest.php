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
