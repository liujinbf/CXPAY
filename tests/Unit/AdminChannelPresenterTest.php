<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\service\AdminChannelPresenter;
use PHPUnit\Framework\TestCase;

final class AdminChannelPresenterTest extends TestCase
{
    public function testEmptyPersistedChannelListStaysEmpty(): void
    {
        if (!class_exists(AdminChannelPresenter::class)) {
            self::fail('AdminChannelPresenter must exist before the channel API contract can be enforced');
        }

        self::assertSame([], AdminChannelPresenter::format([]));
    }

    public function testPersistedChannelRowIsNormalizedWithoutSyntheticDefaults(): void
    {
        if (!class_exists(AdminChannelPresenter::class)) {
            self::fail('AdminChannelPresenter must exist before the channel API contract can be enforced');
        }

        $result = AdminChannelPresenter::format([[
            'id' => 42,
            'title' => '',
            'c_type' => 'wxpay_app_asst',
            'pay_category' => 'wxpay',
            'remark' => 'PC monitor',
            'online_status' => '1',
            'status' => '1',
            'weight' => '80',
        ]]);

        self::assertSame([[
            'id' => 42,
            'code' => 'wxpay_app_asst',
            'name' => 'wxpay_app_asst',
            'pay_type' => 'wxpay',
            'c_type' => 'wxpay_app_asst',
            'remark' => 'PC monitor',
            'online_status' => 1,
            'enabled' => true,
            'weight' => 80,
            'configured' => true,
        ]], $result);
    }
}
