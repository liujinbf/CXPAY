<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use support\LoginRateLimiter;
use support\StructuredLog;
use app\controller\HealthController;
use app\controller\admin\SubAdminController;
use app\controller\admin\AuditLogController;
use app\controller\api\CloudOrderNotifyController;

final class CloudCapabilityAndProductionReadinessTest extends TestCase
{
    public function testHealthControllerInitializationAndUptime(): void
    {
        HealthController::init();
        $bootTime = HealthController::getBootTime();
        self::assertGreaterThan(0, $bootTime);
        self::assertLessThanOrEqual(time(), $bootTime);
    }

    public function testLoginRateLimiterMethodSignaturesAndIsolation(): void
    {
        // 验证静态方法存在且可调用
        self::assertTrue(method_exists(LoginRateLimiter::class, 'tooManyAttempts'));
        self::assertTrue(method_exists(LoginRateLimiter::class, 'increment'));
        self::assertTrue(method_exists(LoginRateLimiter::class, 'clear'));

        // 测试只读特性：未 increment 时 tooManyAttempts 不应返回 true
        $testScope = 'test_unit_' . bin2hex(random_bytes(4));
        $testId    = 'user_unit_' . bin2hex(random_bytes(4));

        $blocked = LoginRateLimiter::tooManyAttempts($testScope, $testId, 3, 60);
        self::assertFalse($blocked);
    }

    public function testStructuredLoggerExecutionWithoutException(): void
    {
        StructuredLog::info('Unit test info message', ['test_key' => 'test_val']);
        StructuredLog::warning('Unit test warn message');
        StructuredLog::error('Unit test error message');
        self::assertTrue(true);
    }

    public function testSubAdminControllerMethodsExist(): void
    {
        $controller = new SubAdminController();
        self::assertTrue(method_exists($controller, 'list'));
        self::assertTrue(method_exists($controller, 'save'));
        self::assertTrue(method_exists($controller, 'delete'));
        self::assertTrue(method_exists($controller, 'toggle'));
        self::assertTrue(method_exists($controller, 'invite'));
        self::assertTrue(method_exists($controller, 'login'));
        self::assertTrue(method_exists($controller, 'activate'));
    }

    public function testAuditLogControllerMethodsExist(): void
    {
        $controller = new AuditLogController();
        self::assertTrue(method_exists($controller, 'list'));
        self::assertTrue(method_exists($controller, 'exportCsv'));
    }

    public function testCloudOrderNotifyControllerMethodsExist(): void
    {
        $controller = new CloudOrderNotifyController();
        self::assertTrue(method_exists($controller, 'handlePluginOrderNotify'));
        self::assertTrue(method_exists($controller, 'handleQuotaNotify'));
    }
}
