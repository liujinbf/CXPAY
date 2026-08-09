<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminChannelFrontendContractTest extends TestCase
{
    public function testOnlyOneActiveAdminChannelLoaderExists(): void
    {
        $path = dirname(__DIR__, 2) . '/public/admin/assets/features/channels.js';
        self::assertFileExists($path);
        $module = file_get_contents($path);

        self::assertIsString($module);
        self::assertSame(
            1,
            substr_count($module, 'async function loadAdminChannels('),
            '重复的 loadAdminChannels() 会覆盖带配置按钮和状态统计的正式实现'
        );
    }

    public function testChannelLoaderProvidesConfigurationEntry(): void
    {
        $root = dirname(__DIR__, 2) . '/public/admin';
        self::assertFileExists($root . '/assets/features/channels.js');
        self::assertFileExists($root . '/views/channels.html');
        $module = file_get_contents($root . '/assets/features/channels.js');
        $view = file_get_contents($root . '/views/channels.html');

        self::assertIsString($module);
        self::assertIsString($view);
        self::assertStringContainsString('/api/admin/channel/config/save', $module);
        self::assertStringContainsString('data-config-key', $module);
        self::assertStringContainsString('data-channel-id', $module);
        self::assertStringContainsString('id="channel-stat-active-count"', $view);
        self::assertStringContainsString('id="channel-config-editor"', $view);
        self::assertStringNotContainsString("'/api/admin/channel/save'", $module);
        self::assertStringNotContainsString('onclick=', $view);
    }
}
