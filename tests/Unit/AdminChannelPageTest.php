<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminChannelPageTest extends TestCase
{
    public function testDriverCountIsLoadedDynamically(): void
    {
        $root = dirname(__DIR__, 2) . '/public/admin';
        self::assertFileExists($root . '/views/channels.html');
        self::assertFileExists($root . '/assets/features/channels.js');
        $view = file_get_contents($root . '/views/channels.html');
        $module = file_get_contents($root . '/assets/features/channels.js');

        self::assertIsString($view);
        self::assertIsString($module);
        self::assertStringContainsString(
            'id="channel-stat-driver-count"',
            $view
        );
        self::assertStringContainsString(
            'loadAdminDriverCount(',
            $module
        );
        self::assertStringNotContainsString(
            '>3 个底层驱动<',
            $view
        );
    }
}
