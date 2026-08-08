<?php

declare(strict_types=1);

namespace WxpayClerk;

final class WechatWebhookHandler
{
    public function __construct(
        private readonly PaymentNotificationParser $parser,
        private readonly PaymentMatchingService $matching,
        private readonly AccountRepository $accounts,
        private readonly string $logFile
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload, ?int $receivedAt = null): bool
    {
        $receivedAt ??= time();
        $typeName = (string) ($payload['TypeName'] ?? '');
        $appId = (string) ($payload['Appid'] ?? '');
        $data = (array) ($payload['Data'] ?? []);

        if (in_array($typeName, ['Login', 'Logout', 'Offline'], true)) {
            $this->handleStatusChange($typeName, $appId, $data, $receivedAt);
            return true;
        }
        if ($typeName !== 'AddMsg' || $data === []) {
            $this->updateHeartbeat($appId, $receivedAt);
            return true;
        }

        $payment = $this->parser->parse($data);
        if (($payment['is_payment'] ?? false) !== true) {
            return true;
        }
        $account = $this->accounts->findByGeweAppId($appId);
        $accountId = $account !== null ? (string) $account['id'] : $appId;
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->matching->ingest([
            'account_id' => $accountId,
            'source_bill_id' => (string) ($payment['source_bill_id'] ?? ''),
            'amount' => (string) $payment['amount'],
            'payer_name' => (string) ($payment['payer_name'] ?? ''),
            'remark' => (string) ($payment['remark'] ?? ''),
            'occurred_at' => (int) $payment['occurred_at'],
            'received_at' => $receivedAt,
            'raw_hash' => hash('sha256', $raw),
        ]);
        $this->log("到账事件已持久化 account_id={$accountId} amount={$payment['amount']}");
        return true;
    }

    /** @param array<string, mixed> $data */
    private function handleStatusChange(string $type, string $appId, array $data, int $now): void
    {
        $wxid = (string) ($data['Wxid'] ?? $data['WxId'] ?? '');
        $accountId = $wxid !== '' ? $wxid : $appId;
        $status = $type === 'Login' ? 'ONLINE' : 'OFFLINE';
        $this->accounts->save($accountId, (string) ($data['Nickname'] ?? ''), $appId, $status, $now);
    }

    private function updateHeartbeat(string $appId, int $now): void
    {
        $account = $this->accounts->findByGeweAppId($appId);
        if ($account !== null) {
            $this->accounts->save(
                (string) $account['id'],
                (string) $account['nickname'],
                $appId,
                'ONLINE',
                $now
            );
        }
    }

    private function log(string $message): void
    {
        if ($this->logFile === '') {
            return;
        }
        $directory = dirname($this->logFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        file_put_contents(
            $this->logFile,
            date('Y-m-d H:i:s') . ' [INFO] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
