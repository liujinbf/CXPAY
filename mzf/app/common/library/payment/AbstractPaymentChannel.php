<?php

namespace app\common\library\payment;

/**
 * 支付通道驱动抽象基类
 *
 * 为可选能力提供默认空实现（等价旧 PayplugClass 的 method_exists 判断）。
 * 具体驱动只需覆盖自己支持的方法。
 */
abstract class AbstractPaymentChannel implements PaymentChannelInterface
{
    /** @var string 通道类型标识，子类必须与 config()['c_type'] 一致 */
    protected string $cType = '';

    abstract public function config(): array;

    abstract public function upchannel(array $channelRow, array $config): array;

    abstract public function getPayQr(array $order, array $config): array;

    public function heartbeatCallback(array $channelRow, array $config): array
    {
        return [];
    }

    public function getPayCurl(array $channelRow, array $config, array $order = []): array
    {
        return [];
    }

    public function getPayCurlCallback(array $channelRow, array $config, $curlData): array
    {
        return [];
    }

    public function tradeClose(array $order, array $config): array
    {
        return ['code' => 1, 'msg' => 'not_supported'];
    }

    /**
     * 回调验签（官方/易支付等第三方回调通道覆盖；默认不通过，安全兜底）。
     * @param array $params 回调参数
     * @param array $config 已解密的通道配置
     */
    public function verifyCallbackSign(array $params, array $config): bool
    {
        return false;
    }

    /**
     * 监控方式：
     *   'server' —— 服务端心跳(如 Cookie 轮询余额/校验)，由常驻 worker 主动检测
     *   'push'   —— 端上推送心跳(如 APP/挂机端上报)，worker 只做超时下线
     *   'none'   —— 无需监控(如官方API通道)
     */
    public function monitorMode(): string
    {
        return 'none';
    }

    /**
     * 服务端监控一次（monitorMode=server 的通道由 worker 调用）。
     * 返回更新后的明文 config，约定字段：
     *   'status' => '1'|false 在线状态
     *   'money'  => 最新余额基线（用于增量对比）
     *   'bill'   => false 或 [ ['price'=>..,'config'=>..], ... ] 新到账账单
     * 默认不做任何监控。
     */
    public function monitor(array $channelRow, array $config): array
    {
        return $config;
    }

    /**
     * 统一成功返回
     */
    protected function ok(array $extra = []): array
    {
        return array_merge(['code' => 1, 'msg' => ''], $extra);
    }

    /**
     * 统一失败返回
     */
    protected function fail(string $msg, int $code = -1): array
    {
        return ['code' => $code, 'msg' => $msg];
    }
}
