<?php

declare(strict_types=1);

namespace app\payment\Drivers\AlipayAppAsst;

use app\payment\AbstractPersonalQrDriver;

/**
 * 支付宝 App 挂机助手驱动插件
 * 挂机 App 统一通过 /api/appasst/push 上报账单。
 */
class Driver extends AbstractPersonalQrDriver
{
    protected function cType(): string
    {
        return 'alipay_app_asst';
    }

    protected function title(): string
    {
        return '支付宝个人收款码（安卓监控）';
    }

    protected function description(): string
    {
        return '展示支付宝个人收款码，由安卓监控端监听到账通知并安全上报';
    }

    protected function qrField(): string
    {
        return 'qr_code_url';
    }

    protected function qrTitle(): string
    {
        return '支付宝个人收款码内容';
    }

    protected function platform(): string
    {
        return 'alipay';
    }
}
