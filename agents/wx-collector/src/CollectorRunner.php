<?php

declare(strict_types=1);

namespace WxCollector;

use RuntimeException;
use Throwable;

final class CollectorRunner
{
    /** @param callable(string):void|null $logger */
    public function __construct(
        private readonly string $collectorId,
        private readonly CloudGatewayInterface $cloud,
        private readonly ProviderAdapterInterface $provider,
        private readonly mixed $logger = null,
    ) {
    }

    /** @return array{sessions:int,events:int,errors:int} */
    public function tick(): array
    {
        $stats = ['sessions' => 0, 'events' => 0, 'errors' => 0];
        try {
            foreach ($this->cloud->pendingSessions() as $task) {
                try {
                    $this->processSession($task);
                    $stats['sessions']++;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $this->log('授权任务失败: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $this->log('获取授权任务失败: ' . $e->getMessage());
        }

        try {
            foreach ($this->provider->pullPaymentEvents(50) as $event) {
                try {
                    $ackToken = trim((string)($event['ack_token'] ?? ''));
                    if ($ackToken === '' || strlen($ackToken) > 255) {
                        throw new RuntimeException('账单缺少合法 ack_token');
                    }
                    unset($event['ack_token']);
                    $response = $this->cloud->submitPaymentEvent($event);
                    if (($response['accepted'] ?? false) !== true) {
                        throw new RuntimeException('云服务未确认接收账单');
                    }
                    $this->provider->acknowledgePaymentEvent($ackToken);
                    $stats['events']++;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    $this->log('账单上报失败: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $this->log('读取数据源账单失败: ' . $e->getMessage());
        }
        return $stats;
    }

    /** @param array<string, mixed> $task */
    private function processSession(array $task): void
    {
        $sessionId = trim((string)($task['id'] ?? ''));
        $status = (string)($task['status'] ?? '');
        $owner = (string)($task['collector_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $sessionId)) {
            throw new RuntimeException('云端返回了非法授权会话');
        }
        if ($owner !== '' && !hash_equals($this->collectorId, $owner)) {
            return;
        }
        if ($status === 'CONFIRMED' && $this->provider instanceof AccountBindingAwareInterface) {
            $accountId = trim((string)($task['account_id'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $accountId)) {
                throw new RuntimeException('待重试绑定的正式账号 ID 不合法');
            }
            $this->provider->bindAuthorizedAccount($sessionId, $accountId);
            $this->cloud->updateSession($sessionId, ['status' => 'BOUND']);
            return;
        }
        if ($status === 'WAITING_COLLECTOR') {
            // 先在云端原子领取，再调用数据源，避免多采集器重复创建二维码。
            $this->cloud->updateSession($sessionId, ['status' => 'CLAIMED']);
            $task['status'] = 'CLAIMED';
            $task['collector_id'] = $this->collectorId;
            $status = 'CLAIMED';
        }
        $state = $status === 'CLAIMED'
            ? $this->provider->startAuthorization($task)
            : $this->provider->pollAuthorization($task);
        if ($state === null) {
            return;
        }
        $nextStatus = strtoupper(trim((string)($state['status'] ?? '')));
        if (!in_array($nextStatus, ['QR_READY', 'SCANNED', 'CONFIRMED', 'FAILED'], true)) {
            throw new RuntimeException('数据源返回了非法授权状态');
        }
        $response = $this->cloud->updateSession($sessionId, $state);
        if ($nextStatus === 'CONFIRMED' && $this->provider instanceof AccountBindingAwareInterface) {
            $accountId = trim((string)($response['account_id'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $accountId)) {
                throw new RuntimeException('云服务没有返回合法的正式账号 ID');
            }
            $this->provider->bindAuthorizedAccount($sessionId, $accountId);
            $this->cloud->updateSession($sessionId, ['status' => 'BOUND']);
        }
    }

    private function log(string $message): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)($message);
        }
    }
}
