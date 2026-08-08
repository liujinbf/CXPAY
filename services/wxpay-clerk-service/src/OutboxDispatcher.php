<?php

declare(strict_types=1);

namespace WxpayClerk;

use RuntimeException;
use Throwable;

final class OutboxDispatcher
{
    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly CallbackPayloadSigner $signer,
        private readonly CallbackTransportInterface $transport,
        private readonly string $notifyUrl,
        private readonly int $maxAttempts = 12,
        private readonly int $leaseSeconds = 60
    ) {
    }

    public function dispatchOne(int $now): bool
    {
        $task = $this->outbox->claimDue($now, $this->leaseSeconds);
        if ($task === null) {
            return false;
        }
        try {
            $result = $this->transport->post($this->notifyUrl, $this->signer->fields($task, $now));
            if ($result['status'] < 200
                || $result['status'] >= 300
                || trim($result['body']) !== 'success') {
                throw new RuntimeException('CXPAY 未确认回调');
            }
            $this->outbox->markSent((int) $task['id'], $now);
        } catch (Throwable $exception) {
            $attempts = (int) $task['attempts'];
            if ($attempts >= $this->maxAttempts) {
                $this->outbox->markFailed((int) $task['id'], $attempts, $exception->getMessage());
            } else {
                $delay = min(3600, 5 * (2 ** max(0, $attempts - 1)));
                $this->outbox->reschedule(
                    (int) $task['id'],
                    $now + $delay,
                    $attempts,
                    $exception->getMessage()
                );
            }
        }
        return true;
    }
}
