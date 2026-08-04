<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\controller\admin\SystemUpdateController;
use app\service\SystemUpdateGuard;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use support\Request;

final class SystemUpdateGuardTest extends TestCase
{
    public function testDisabledGuardBlocksEveryUpdateEndpoint(): void
    {
        if (!class_exists(SystemUpdateGuard::class)) {
            self::fail('SystemUpdateGuard must exist before update endpoints can be secured');
        }

        $guard = new SystemUpdateGuard(false);
        $blocked = $guard->disabledResponse();
        self::assertNotNull($blocked);
        self::assertSame(403, $blocked->getStatusCode());

        $controllerReflection = new ReflectionClass(SystemUpdateController::class);
        $constructor = $controllerReflection->getConstructor();
        self::assertNotNull($constructor, 'SystemUpdateController must accept an injectable guard');

        $guardType = $constructor->getParameters()[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $guardType);
        self::assertSame(SystemUpdateGuard::class, $guardType->getName());
        self::assertTrue($guardType->allowsNull());

        $controller = new SystemUpdateController($guard);
        $request = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();

        foreach ([
            'checkUpdate',
            'doUpdate',
            'versionHistory',
            'pollProgress',
            'getUpdateLog',
            'doRollback',
        ] as $method) {
            $response = $controller->{$method}($request);
            self::assertSame(403, $response->getStatusCode(), "{$method} must be blocked when updates are disabled");
        }
    }

    public function testEnabledGuardAllowsControllerToContinue(): void
    {
        if (!class_exists(SystemUpdateGuard::class)) {
            self::fail('SystemUpdateGuard must exist before update endpoints can be secured');
        }

        self::assertNull((new SystemUpdateGuard(true))->disabledResponse());
    }
}
