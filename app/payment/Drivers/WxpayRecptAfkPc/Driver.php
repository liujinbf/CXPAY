<?php

declare(strict_types=1);

namespace app\payment\Drivers\WxpayRecptAfkPc;

use app\payment\AbstractPersonalQrDriver;

/**
 * 微信收款码 PC 挂机账单捕获驱动插件
 */
class Driver extends AbstractPersonalQrDriver
{
    protected function cType(): string
    {
        return 'wxpay_recpt_afk_pc';
    }

    protected function title(): string
    {
        return '微信个人收款码 / 赞赏码（PC监控）';
    }

    protected function description(): string
    {
        return '展示微信个人收款码或赞赏码，由 Windows 监控端自动抓取到账记录并安全上报；支持微信收款单、赞赏码和小账本记录识别';
    }

    protected function qrField(): string
    {
        return 'qr_code_url';
    }

    protected function qrTitle(): string
    {
        return '微信个人收款码/赞赏码内容';
    }

    protected function platform(): string
    {
        return 'wxpay';
    }
}
