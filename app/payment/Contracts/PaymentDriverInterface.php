<?php

declare(strict_types=1);

namespace app\payment\Contracts;

/**
 * 核心支付驱动契约接口 (完全兼容旧系统动态表单与 upchannel 校验)
 */
interface PaymentDriverInterface
{
    /**
     * 下单出码/生成拉起地址
     */
    public function pay(array $params, array $config): array;

    /**
     * 上游异步通知验签与解析
     */
    public function notify(array $params, array $config): array;

    /**
     * 查单接口
     */
    public function query(string $tradeNo, array $config): array;

    /**
     * 驱动元数据与后台渲染动态表单 inputs 定义
     */
    public function getMeta(): array;

    /**
     * 通道保存前校验与配置规范化 (upchannel)
     */
    public function upchannel(array $channelRow, array $config): array;
}
