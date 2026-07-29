<?php

declare(strict_types=1);

namespace app\payment\Drivers\WxpayAppAsst;

use app\payment\AbstractPersonalQrDriver;

/**
 * 微信 App 挂机助手驱动插件
 */
class Driver extends AbstractPersonalQrDriver
{
    protected function cType(): string
    {
        return 'wxpay_app_asst';
    }

    protected function title(): string
    {
        return '微信个人收款码（安卓监控）';
    }

    protected function description(): string
    {
        return '展示微信个人收款码，由安卓监控端监听到账通知并安全上报';
    }

    protected function qrField(): string
    {
        return 'qr_code_url';
    }

    protected function qrTitle(): string
    {
        return '微信个人收款码内容';
    }

    protected function platform(): string
    {
        return 'wxpay';
    }
}
