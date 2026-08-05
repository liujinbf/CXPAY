<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminChannelPageTest extends TestCase
{
    public function testDriverCountIsLoadedDynamically(): void
    {
        $html = file_get_contents(
            dirname(__DIR__, 2) . '/public/admin/index.html'
        );

        self::assertIsString($html);
        self::assertStringContainsString(
            'id="channel-stat-driver-count"',
            $html
        );
        self::assertStringContainsString(
            'loadAdminDriverCount()',
            $html
        );
        self::assertStringNotContainsString(
            '>3 个底层驱动<',
            $html
        );
    }
}
