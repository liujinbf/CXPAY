<?php

namespace app\common\library\payment\plugins\alipay_app_asst;

use app\common\library\payment\AbstractPaymentChannel;

/**
 * Peak助手-支付宝APP监控 驱动（alipay_app_asst）
 *
 * 移植自旧 protected/payplug/alipay_app_asst/config.php。
 * 原理：商户上传固定收款码(qr_url)，手机端 Peak 助手 APP 监控支付宝到账通知，
 *   通过 openapi/Asst/push 推送到账 → 结算匹配。收银台展示固定码 + 精确金额。
 *
 * 特征：switch_qr_url=true（用固定收款码，无文本配置字段），出码即返回该固定码。
 * 旧方法映射：config→config()；upchannel→upchannel()；
 *   gethrcurl_callback→heartbeatCallback()；getpayqrurl→getPayQr()。
 *   （旧插件无 getpaycurl / trade_close，故不覆盖。）
 */
class Driver extends AbstractPaymentChannel
{
    protected string $cType = 'alipay_app_asst';

    public function monitorMode(): string
    {
        return 'push'; // APP 端上报心跳(openapi/Asst/heart) + 到账推送(openapi/Asst/push)
    }

    public function config(): array
    {
        return [
            'version'       => '1.0.0',
            'name'          => 'Peak助手-支付宝APP监控',
            'author'        => '小乐',
            'link'          => 'http://xiaole.ink',
            'type'          => 'alipay',
            'c_type'        => 'alipay_app_asst',
            'switch_qr_url' => true,   // 使用上传的固定收款码
            'getqrlogin'    => false,
            'inputs'        => [],     // 无文本字段，仅需上传收款码
            'note'          => '<span style="color:red" name="check_status" id="check_status">原理：监控手机顶部的收款到帐通知详细<br>★支持：个人码 商家码 店员小号监听<br>安全稳定不封号、不异常、不掉线</span>',
        ];
    }

    public function upchannel(array $channelRow, array $config): array
    {
        // 兼容旧库：qr_url 可能来自已存配置
        $old = [];
        if (!empty($channelRow['config'])) {
            $decoded = is_array($channelRow['config']) ? $channelRow['config'] : json_decode((string) $channelRow['config'], true);
            $old = is_array($decoded) ? $decoded : [];
        }

        $qrUrl = isset($config['qr_url']) ? trim((string) $config['qr_url']) : '';
        if ($qrUrl === '' && !empty($old['qr_url'])) {
            $qrUrl = $old['qr_url'];
        }
        if ($qrUrl === '') {
            return $this->fail('请先上传二维码');
        }

        $config['qr_url'] = $qrUrl;
        $config['msg']    = '已保存APP助手二维码配置';
        return $config;
    }

    /**
     * 出码：返回固定收款码（收银台展示）。
     * 对应旧 getpayqrurl：$config['config']=false（不递增，按推送金额匹配），直接返回 qr_url。
     */
    public function getPayQr(array $order, array $config): array
    {
        $qr = $config['qr_url'] ?? '';
        if (!$qr) {
            return $this->fail('通道未配置收款码');
        }
        return $this->ok(['qr' => $qr, 'type' => 'qr']);
    }

    /**
     * 心跳状态：heartbeat_time 未过期视为在线（对应旧 gethrcurl_callback）
     */
    public function heartbeatCallback(array $channelRow, array $config): array
    {
        $config['status'] = (!empty($config['heartbeat_time']) && $config['heartbeat_time'] >= time()) ? '1' : false;
        return $config;
    }
}
