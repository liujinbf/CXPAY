<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ServiceIsolationTest extends TestCase
{
    public function testIndependentAutoloaderCannotResolveCxpayApplicationClasses(): void
    {
        $serviceRoot = dirname(__DIR__, 2);
        $probe = <<<'PHP'
<?php
require $argv[1] . '/vendor/autoload.php';
echo json_encode([
    'cloud' => class_exists(CloudControl\Shared\Http\HealthController::class),
    'cxpay' => class_exists(app\model\User::class),
], JSON_THROW_ON_ERROR);
PHP;
        $probeFile = tempnam(sys_get_temp_dir(), 'cloud-isolation-');
        self::assertNotFalse($probeFile);
        file_put_contents($probeFile, $probe);

        try {
            $command = escapeshellarg(PHP_BINARY)
                . ' ' . escapeshellarg($probeFile)
                . ' ' . escapeshellarg($serviceRoot);
            exec($command, $output, $exitCode);
        } finally {
            @unlink($probeFile);
        }

        self::assertSame(0, $exitCode);
        self::assertSame(
            ['cloud' => true, 'cxpay' => false],
            json_decode(implode("\n", $output), true, 8, JSON_THROW_ON_ERROR)
        );
    }
}
