<?php

declare(strict_types=1);

namespace app\service\order;

/**
 * 生成带时间前缀和加密随机熵的平台订单号。
 */
final class OrderNumberGenerator
{
    public function generate(): string
    {
        return 'CX' . gmdate('YmdHis') . strtoupper(bin2hex(random_bytes(10)));
    }
}
