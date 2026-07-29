<?php

declare(strict_types=1);

namespace app\payment\Drivers\QqpayAppAsst;

use app\payment\AbstractPersonalQrDriver;

/**
 * QQ 钱包个人收款码免签挂机助手驱动插件
 */
class Driver extends AbstractPersonalQrDriver
{
    protected function cType(): string
    {
        return 'qqpay_app_asst';
    }

    protected function title(): string
    {
        return 'QQ钱包个人收款码（安卓监控）';
    }

    protected function description(): string
    {
        return '展示QQ钱包个人收款码，由安卓监控端监听到账通知并安全上报';
    }

    protected function qrField(): string
    {
        return 'qr_url';
    }

    protected function qrTitle(): string
    {
        return 'QQ钱包个人收款码内容';
    }

    protected function platform(): string
    {
        return 'qqpay';
    }
}
