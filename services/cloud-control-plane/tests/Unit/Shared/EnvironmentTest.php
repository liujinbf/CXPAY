<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit\Shared;

use CloudControl\Shared\Config\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testReadsProcessEnvironmentAndUsesDefaultWhenMissing(): void
    {
        putenv('CLOUD_TEST_ENVIRONMENT_VALUE=isolated-cloud');

        try {
            self::assertSame(
                'isolated-cloud',
                Environment::get('CLOUD_TEST_ENVIRONMENT_VALUE', 'fallback')
            );
            self::assertSame(
                'fallback',
                Environment::get('CLOUD_TEST_ENVIRONMENT_MISSING', 'fallback')
            );
        } finally {
            putenv('CLOUD_TEST_ENVIRONMENT_VALUE');
        }
    }
}
