<?php

declare(strict_types=1);

namespace app\payment\Contracts;

/**
 * 服务端账单轮询契约。
 *
 * 驱动只负责从收款端读取账单并规范化；幂等、订单匹配和回调仍由 CXPAY 核心处理。
 */
interface ServerPollingDriverInterface
{
    /**
     * @return list<array{
     *   source_bill_id:string,
     *   amount:string,
     *   occurred_at:int,
     *   remark?:string,
     *   raw_hash?:string
     * }>
     */
    public function pollPaymentEvents(array $config, int $since, int $until): array;
}
