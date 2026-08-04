<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminChannelFrontendContractTest extends TestCase
{
    public function testOnlyOneActiveAdminChannelLoaderExists(): void
    {
        $path = dirname(__DIR__, 2) . '/public/admin/index.html';
        $html = file_get_contents($path);

        self::assertIsString($html);
        self::assertSame(
            1,
            substr_count($html, 'async function loadAdminChannels()'),
            '重复的 loadAdminChannels() 会覆盖带配置按钮和状态统计的正式实现'
        );
    }

    public function testChannelLoaderProvidesConfigurationEntry(): void
    {
        $path = dirname(__DIR__, 2) . '/public/admin/index.html';
        $html = file_get_contents($path);

        self::assertIsString($html);
        self::assertStringContainsString(
            'openChannelConfigEditor(${c.id})',
            $html
        );
        self::assertStringContainsString(
            "document.getElementById('channel-stat-active-count')",
            $html
        );
    }
}
