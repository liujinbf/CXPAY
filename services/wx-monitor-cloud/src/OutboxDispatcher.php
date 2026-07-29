<?php

declare(strict_types=1);

namespace WxMonitorCloud;

use GuzzleHttp\Client;
use PDO;
use Throwable;

final class OutboxDispatcher
{
    public function __construct(private readonly PDO $pdo, private readonly SecretVault $vault)
    {
    }

    public function dispatchDue(int $limit = 20): int
    {
        $limit = max(1, min(100, $limit));
        $this->pdo->prepare(
            "UPDATE callback_outbox SET status = 'RETRY' WHERE status = 'PROCESSING' AND next_attempt_at <= ?"
        )->execute([time()]);
        $rows = $this->pdo->query(
            "SELECT * FROM callback_outbox WHERE status IN ('PENDING', 'RETRY')
             AND next_attempt_at <= " . time() . " ORDER BY id ASC LIMIT {$limit}"
        )->fetchAll();
        $completed = 0;
        foreach ($rows as $row) {
            $claimed = $this->pdo->prepare(
                "UPDATE callback_outbox SET status = 'PROCESSING', next_attempt_at = ?
                 WHERE id = ? AND status IN ('PENDING', 'RETRY')"
            );
            $claimed->execute([time() + 60, $row['id']]);
            if ($claimed->rowCount() !== 1) {
                continue;
            }
            try {
                $this->deliver($row);
                $this->pdo->prepare("UPDATE callback_outbox SET status = 'SENT', attempts = attempts + 1, last_error = '' WHERE id = ?")
                    ->execute([$row['id']]);
                $completed++;
            } catch (Throwable $e) {
                $attempts = (int)$row['attempts'] + 1;
                $status = $attempts >= 8 ? 'FAILED' : 'RETRY';
                $delay = min(3600, 10 * (2 ** min(8, $attempts - 1)));
                $this->pdo->prepare(
                    'UPDATE callback_outbox SET status = ?, attempts = ?, next_attempt_at = ?, last_error = ? WHERE id = ?'
                )->execute([$status, $attempts, time() + $delay, mb_substr($e->getMessage(), 0, 500), $row['id']]);
            }
        }
        return $completed;
    }

    /** @param array<string, mixed> $row */
    private function deliver(array $row): void
    {
        if (!$this->isPublicHttpsUrl((string)$row['callback_url'])) {
            throw new \RuntimeException('客户端回调地址不是可解析的公网 HTTPS 地址');
        }
        $principal = $this->pdo->prepare('SELECT * FROM principals WHERE id = ? AND role = ? AND status = 1');
        $principal->execute([$row['client_id'], 'client']);
        $principalRow = $principal->fetch();
        if (!$principalRow) {
            throw new \RuntimeException('客户端回调密钥不存在');
        }
        $payload = json_decode((string)$row['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('回调发件箱数据损坏');
        }
        $payload['timestamp'] = time();
        $payload['nonce'] = bin2hex(random_bytes(16));
        ksort($payload);
        $payload['sign'] = hash_hmac(
            'sha256',
            http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            (new PrincipalKeyManager($this->pdo, $this->vault))->activeSecret($principalRow, 'response')
        );
        $response = (new Client([
            'timeout' => 8.0,
            'connect_timeout' => 3.0,
            'verify' => true,
            'http_errors' => false,
            'allow_redirects' => false,
        ]))->post((string)$row['callback_url'], ['form_params' => $payload]);
        if ($response->getStatusCode() !== 200 || strtolower(trim((string)$response->getBody())) !== 'success') {
            throw new \RuntimeException('CXPAY 回调未返回 success');
        }
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower(rtrim((string)$parts['host'], '.'));
        if ($host === 'localhost') {
            return false;
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_values(array_filter(array_map(
                static fn(array $record): string => (string)($record['ip'] ?? $record['ipv6'] ?? ''),
                dns_get_record($host, DNS_A | DNS_AAAA) ?: []
            )));
        if ($addresses === []) {
            return false;
        }
        foreach (array_unique($addresses) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }
        return true;
    }
}
