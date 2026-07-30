<?php

declare(strict_types=1);

namespace WxMonitorCloud;

use RuntimeException;

/**
 * 个人微信号店员消息接收解析器
 * 负责接收店员微信号（PC Hook / Android 监控）上报的到账消息，解析微信单号、金额与商户绑定关系。
 */
final class PersonalWechatCallbackReceiver
{
    /**
     * 处理一次个人号店员消息 POST 数据
     *
     * @return array{source_bill_id: string, amount: string, occurred_at: int, talker: string, raw_xml: string}
     */
    public function parseMsgPayload(array $payload): array
    {
        $type = (int)($payload['Type'] ?? 0);
        // 只处理 MsgType=49 (App 消息，包含转账/收款)
        if ($type !== 49 && $type !== 2000) {
            throw new RuntimeException('非收款或转账类消息类型');
        }

        $xml = (string)($payload['StrContent'] ?? $payload['content'] ?? '');
        if ($xml === '') {
            throw new RuntimeException('消息 XML 内容为空');
        }

        // 必须是转账收款 (paysubtype=1)
        if (str_contains($xml, '<type>2000</type>') && !preg_match('/<paysubtype>1<\/paysubtype>/', $xml)) {
            throw new RuntimeException('忽略退款或发出转账消息');
        }

        // 提取金额
        $amount = '0.00';
        if (preg_match('/<feedesc>¥([\d.]+)/', $xml, $amtMatch) || preg_match('/<total_fee>([\d.]+)/', $xml, $amtMatch)) {
            $amount = number_format((float)$amtMatch[1], 2, '.', '');
        }
        if ((float)$amount <= 0) {
            throw new RuntimeException('无法从消息中解析有效金额');
        }

        // 提取微信官方唯一交易单号 transcationid
        $billId = '';
        if (preg_match('/<transcationid>([\d]+)<\/transcationid>/', $xml, $tidMatch)) {
            $billId = $tidMatch[1];
        }
        if ($billId === '') {
            $billId = 'WXPC-' . (string)($payload['MsgSvrID'] ?? bin2hex(random_bytes(8)));
        }

        return [
            'source_bill_id' => $billId,
            'amount'         => $amount,
            'occurred_at'    => (int)($payload['CreateTime'] ?? time()),
            'talker'         => (string)($payload['StrTalker'] ?? ''),
            'raw_xml'        => $xml,
        ];
    }
}
