<?php

namespace app\common\library\payment;

/**
 * 支付通道驱动接口
 *
 * 对应旧系统 payplug/{c_type}/config.php 中的静态方法契约，
 * 由 PayplugClass 反射调用。重构为显式接口 + 驱动注册（config/payment.php）。
 *
 * 说明：旧实现的方法是「按需存在」（PayplugClass 用 method_exists 判断），
 * 因此这里除元数据与出码/校验外，联网/轮询类方法给出默认空实现（见 AbstractPaymentChannel），
 * 驱动按能力覆盖。
 */
interface PaymentChannelInterface
{
    /**
     * 插件元数据与配置字段定义（version/name/type/c_type/inputs/note ...）
     * @return array
     */
    public function config(): array;

    /**
     * 后台保存通道配置前的校验/规范化。
     * 成功返回规范化后的 $config 数组；失败返回 ['code'=>-1,'msg'=>'...']。
     *
     * @param array $channelRow 通道行
     * @param array $config     待保存的明文配置
     * @return array
     */
    public function upchannel(array $channelRow, array $config): array;

    /**
     * 生成支付二维码 / 支付链接。
     * 返回 ['code'=>1,'msg'=>'','qr'=>'...','type'=>'qr|url', ...]
     *
     * @param array $order  订单行
     * @param array $config 已解密的通道配置
     * @return array
     */
    public function getPayQr(array $order, array $config): array;

    /**
     * 组装心跳检测的多线程请求参数（可选）
     */
    public function heartbeatCallback(array $channelRow, array $config): array;

    /**
     * 组装轮询取单的请求（可选）
     */
    public function getPayCurl(array $channelRow, array $config, array $order = []): array;

    /**
     * 处理轮询取单的返回（可选）
     */
    public function getPayCurlCallback(array $channelRow, array $config, $curlData): array;

    /**
     * 关闭订单（可选）
     */
    public function tradeClose(array $order, array $config): array;
}
