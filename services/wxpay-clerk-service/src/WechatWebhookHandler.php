<?php

declare(strict_types=1);

namespace WxpayClerk;

/**
 * 接收并分发 gewe 推送的微信消息 Webhook。
 *
 * 端点：POST /wechat/message（仅 gewe 内网 IP 可访问）
 *
 * gewe Webhook 消息体结构：
 * {
 *   "TypeName": "AddMsg",
 *   "Appid": "gewe_app_id",
 *   "Data": {
 *     "MsgId": 123,
 *     "FromUserName": {"string": "fmessage"},
 *     "ToUserName":   {"string": "wxid_xxx"},
 *     "MsgType": 49,
 *     "Content":    {"string": "<msg>...</msg>"},
 *     "PushContent": "收款助手: [收款通知]¥12.50",
 *     "CreateTime": 1722959329,
 *     "NewMsgId": 987654321
 *   }
 * }
 */
final class WechatWebhookHandler
{
    public function __construct(
        private readonly PaymentNotificationParser $parser,
        private readonly OrderMatcher              $matcher,
        private readonly CxpayCallback             $callback,
        private readonly OrderStore                $store,
        private readonly string                    $logFile
    ) {}

    /**
     * 处理来自 gewe 的 Webhook 推送，返回是否成功处理。
     *
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): bool
    {
        $typeName = (string)($payload['TypeName'] ?? '');
        $appId    = (string)($payload['Appid']    ?? '');
        $data     = (array)($payload['Data']       ?? []);

        // 仅处理收到的新消息
        if ($typeName !== 'AddMsg' || $data === []) {
            $this->updateAccountHeartbeat($appId);
            return true; // 其他类型消息（登录成功、断线等）直接返回 OK
        }

        // 处理登录/断线状态变更
        if (in_array($typeName, ['Login', 'Logout', 'Offline'], true)) {
            $this->handleStatusChange($typeName, $appId, $data);
            return true;
        }

        // 尝试解析为支付通知
        $payment = $this->parser->parse($data);
        if (!$payment['is_payment']) {
            return true; // 非收款消息，忽略
        }

        // 根据 gewe AppId 找对应的账号
        $account   = $this->findAccountByGeweAppId($appId);
        $accountId = $account !== null ? (string)$account['id'] : $appId;

        $this->log("收到到账通知 accountId={$accountId} amount={$payment['amount']} remark={$payment['remark']}");

        // 匹配订单
        $matchResult = $this->matcher->match($accountId, $payment);

        if ($matchResult['matched']) {
            $outTradeNo   = (string)($matchResult['out_trade_no']   ?? '');
            $sourceBillId = (string)($matchResult['source_bill_id'] ?? '');
            $this->log("匹配成功 out_trade_no={$outTradeNo} reason={$matchResult['reason']}");

            try {
                $this->callback->send(
                    $outTradeNo,
                    $sourceBillId,
                    (string)$payment['amount'],
                    (int)$payment['occurred_at']
                );
                $this->log("CXPAY 回调发送成功 out_trade_no={$outTradeNo}");
            } catch (\Throwable $e) {
                $this->log("CXPAY 回调失败 out_trade_no={$outTradeNo} error=" . $e->getMessage(), 'ERROR');
                // 回调失败不影响 HTTP 200 响应，防止 gewe 重试推送
            }
        } else {
            $eventId = (int)($matchResult['review_event_id'] ?? 0);
            $this->log("无法自动匹配，已创建审核事件 review_event_id={$eventId} reason={$matchResult['reason']}", 'WARN');
        }

        return true;
    }

    // ─── 辅助方法 ─────────────────────────────────────────────────────────────

    private function handleStatusChange(string $type, string $appId, array $data): void
    {
        $status = match ($type) {
            'Login'   => 'ONLINE',
            'Logout'  => 'OFFLINE',
            'Offline' => 'OFFLINE',
            default   => 'UNKNOWN',
        };
        $wxid     = (string)($data['Wxid']    ?? $data['WxId']    ?? '');
        $nickname = (string)($data['Nickname'] ?? '');
        $accountId = $wxid !== '' ? $wxid : $appId;
        $this->store->upsertAccount($accountId, $nickname, $appId, $status);
        $this->log("账号状态变更 accountId={$accountId} status={$status}");
    }

    private function updateAccountHeartbeat(string $appId): void
    {
        $account = $this->findAccountByGeweAppId($appId);
        if ($account !== null) {
            $this->store->upsertAccount(
                (string)$account['id'],
                (string)($account['nickname'] ?? ''),
                $appId,
                'ONLINE'
            );
        }
    }

    /**
     * 以 gewe_app_id 查找账号。
     * 已改为直接调用 OrderStore::getAccountByGeweAppId()，利用 SQLite 索引 O(1) 定位。
     *
     * @return array<string, mixed>|null
     */
    private function findAccountByGeweAppId(string $appId): ?array
    {
        return $this->store->getAccountByGeweAppId($appId);
    }

    private function log(string $message, string $level = 'INFO'): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = date('Y-m-d H:i:s') . " [{$level}] " . $message . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
