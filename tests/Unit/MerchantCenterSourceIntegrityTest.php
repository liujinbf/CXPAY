<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MerchantCenterSourceIntegrityTest extends TestCase
{
    private string $html;

    protected function setUp(): void
    {
        $html = file_get_contents(dirname(__DIR__, 2) . '/public/merchant_center.html');
        self::assertIsString($html);
        $this->html = $html;
    }

    public function testSourceHasNoMergeConflictMarkers(): void
    {
        self::assertDoesNotMatchRegularExpression('/^(<<<<<<<|=======|>>>>>>>)\s.*$/m', $this->html);
    }

    public function testStaticMarkupIdsAreUnique(): void
    {
        $markup = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $this->html);
        self::assertIsString($markup);
        preg_match_all('/\bid="([^"]+)"/', $markup, $matches);
        $duplicates = array_keys(array_filter(
            array_count_values($matches[1]),
            static fn (int $count): bool => $count > 1
        ));

        self::assertSame([], $duplicates, '商户中心静态 DOM id 不得重复');
    }

    public function testNamedBusinessFunctionsAreUnique(): void
    {
        preg_match_all(
            '/\b(?:async\s+)?function\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/',
            $this->html,
            $matches
        );
        $duplicates = array_keys(array_filter(
            array_count_values($matches[1]),
            static fn (int $count): bool => $count > 1
        ));

        self::assertSame([], $duplicates, '商户中心动作不得依赖后声明覆盖前声明');
    }

    public function testEffectiveDashboardKeepsRecentOrdersAndPlanSummary(): void
    {
        self::assertStringContainsString('/api/merchant/order/list?page_size=5', $this->html);
        self::assertStringContainsString('dashboard-plan-name', $this->html);
        self::assertStringContainsString('dashboard-recent-orders-tbody', $this->html);
    }
}
