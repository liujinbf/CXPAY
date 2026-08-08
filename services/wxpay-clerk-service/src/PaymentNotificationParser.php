<?php

declare(strict_types=1);

namespace WxpayClerk;

/**
 * 微信到账通知消息解析器。
 *
 * gewe 推送的消息体结构：
 * {
 *   "TypeName": "AddMsg",
 *   "Appid": "gewe_app_id",
 *   "Data": {
 *     "MsgId": 123,
 *     "FromUserName": {"string": "fmessage"},  // 系统通知来源
 *     "ToUserName":   {"string": "wxid_xxx"},   // 本服务店员账号
 *     "MsgType": 49,                            // 49 = App/链接消息
 *     "Content":    {"string": "<msg>...</msg>"},
 *     "PushContent": "收款助手: [收款通知]¥12.50",
 *     "CreateTime": 1722959329,
 *     "NewMsgId": 987654321
 *   }
 * }
 *
 * 微信收款到账通知有两种常见格式：
 *
 * 格式 A（MsgType=49，XML App消息，旧账号常见）：
 *   Content XML中 des 字段: "向收款码「商户名」收款 ¥12.50，付款方：张三，备注：ORDER123"
 *
 * 格式 B（MsgType=1，文本消息，少数情况）：
 *   "收款助手\n向收款码「商户名」成功收款12.50元\n付款方：张三\n付款备注：ORDER123"
 *
 * 格式 C（小账本通知，MsgType=49，title包含金额）：
 *   title: "你的小账本有新收入"，des 包含详情
 */
final class PaymentNotificationParser
{
    /**
     * 判断传入的消息是否是支付到账通知，并解析关键字段。
     *
     * @param array<string, mixed> $msgData gewe Data 字段
     * @return array{
     *   is_payment: bool,
     *   amount?: string,
     *   payer_name?: string,
     *   remark?: string,
     *   occurred_at?: int,
     *   source_bill_id?: string
     * }
     */
    public function parse(array $msgData): array
    {
        $msgType     = (int)($msgData['MsgType'] ?? 0);
        $fromUser    = $this->extractStr($msgData['FromUserName'] ?? '');
        $createTime  = (int)($msgData['CreateTime']  ?? time());
        $newMsgId    = (string)($msgData['NewMsgId']  ?? $msgData['MsgId'] ?? '');
        $pushContent = (string)($msgData['PushContent'] ?? '');
        $content     = $this->extractStr($msgData['Content'] ?? '');

        // 仅处理来自系统通知账号的消息
        // fmessage = 系统消息，weixin = 微信团队，收款助手的 wxid 通常是 gh_开头或 fmessage
        $isSystemSender = in_array($fromUser, ['fmessage', 'weixin'], true)
            || str_starts_with($fromUser, 'gh_');

        if (!$isSystemSender && !$this->looksLikePayment($pushContent)) {
            return ['is_payment' => false];
        }

        // 尝试各种格式解析
        $result = match ($msgType) {
            49   => $this->parseAppMessage($content, $createTime, $newMsgId),
            1    => $this->parseTextMessage($content, $createTime, $newMsgId),
            default => null,
        };

        if ($result === null && $this->looksLikePayment($pushContent)) {
            // fallback：仅从 PushContent 提取金额
            $result = $this->parseFromPushContent($pushContent, $createTime, $newMsgId);
        }

        return $result ?? ['is_payment' => false];
    }

    // ─── 格式 A / C：MsgType 49 XML App 消息 ─────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function parseAppMessage(string $content, int $createTime, string $newMsgId): ?array
    {
        // 压制 XML 解析警告
        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($content);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if ($xml === false) {
            return null;
        }

        $title = (string)($xml->appmsg->title ?? '');
        $des   = (string)($xml->appmsg->des   ?? '');
        $url   = (string)($xml->appmsg->url   ?? '');

        // 判断是否是收款通知
        $isPaymentMsg = str_contains($title, '收款') || str_contains($des, '收款')
            || str_contains($title, '到账') || str_contains($url, 'wxaurl.cn/pay');

        if (!$isPaymentMsg) {
            return null;
        }

        // 合并 title + des 用于提取信息
        $combined = $title . "\n" . $des;
        $amount   = $this->extractAmount($combined);
        if ($amount === null) {
            return null;
        }

        return [
            'is_payment'     => true,
            'amount'         => $amount,
            'payer_name'     => $this->extractPayerName($combined),
            'remark'         => $this->extractRemark($combined),
            'occurred_at'    => $createTime,
            'source_bill_id' => $newMsgId,
        ];
    }

    // ─── 格式 B：MsgType 1 纯文本消息 ────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function parseTextMessage(string $content, int $createTime, string $newMsgId): ?array
    {
        if (!str_contains($content, '收款') && !str_contains($content, '到账')) {
            return null;
        }
        $amount = $this->extractAmount($content);
        if ($amount === null) {
            return null;
        }
        return [
            'is_payment'     => true,
            'amount'         => $amount,
            'payer_name'     => $this->extractPayerName($content),
            'remark'         => $this->extractRemark($content),
            'occurred_at'    => $createTime,
            'source_bill_id' => $newMsgId,
        ];
    }

    // ─── Fallback：仅从 PushContent 提取 ─────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function parseFromPushContent(string $pushContent, int $createTime, string $newMsgId): ?array
    {
        $amount = $this->extractAmount($pushContent);
        if ($amount === null) {
            return null;
        }
        return [
            'is_payment'     => true,
            'amount'         => $amount,
            'payer_name'     => '',
            'remark'         => '',
            'occurred_at'    => $createTime,
            'source_bill_id' => $newMsgId,
        ];
    }

    // ─── 工具方法 ─────────────────────────────────────────────────────────────

    /**
     * 从文本中提取金额（两位小数格式）。
     */
    private function extractAmount(string $text): ?string
    {
        // 匹配 ¥12.50、12.50元、收款12.5元 等格式
        if (preg_match('/[¥￥]?\s*(\d{1,8}(?:\.\d{1,2})?)(?:\s*元|\b)/', $text, $m)) {
            $val = number_format((float)$m[1], 2, '.', '');
            if ((float)$val > 0 && (float)$val <= 50000) {
                return $val;
            }
        }
        return null;
    }

    /**
     * 提取付款人名称。
     */
    private function extractPayerName(string $text): string
    {
        // 付款方：张三 / 付款人：张三
        if (preg_match('/付款[方人：:]\s*([^\n，,。.]{1,20})/', $text, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * 提取备注（可能包含 out_trade_no）。
     */
    private function extractRemark(string $text): string
    {
        // 备注：xxx / 付款备注：xxx
        if (preg_match('/(?:付款)?备注[：:]\s*([^\n，,。.]{1,128})/', $text, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function looksLikePayment(string $text): bool
    {
        return str_contains($text, '收款') || str_contains($text, '到账') || str_contains($text, '¥');
    }

    private function extractStr(mixed $val): string
    {
        if (is_array($val)) {
            return (string)($val['string'] ?? '');
        }
        return (string)$val;
    }
}
