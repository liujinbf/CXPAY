<?php

declare(strict_types=1);

namespace WxpayClerk;

use Closure;
use RuntimeException;

final class CallbackPayloadSigner
{
    private Closure $nonceFactory;

    public function __construct(private readonly string $callbackSecret, ?callable $nonceFactory = null)
    {
        $length = strlen($callbackSecret);
        if ($length < 32 || $length > 128) {
            throw new RuntimeException('callback_secret 长度必须为 32 至 128 位');
        }
        $this->nonceFactory = $nonceFactory !== null
            ? Closure::fromCallable($nonceFactory)
            : static fn (): string => bin2hex(random_bytes(16));
    }

    /** @param array<string, mixed> $task @return array<string, string> */
    public function fields(array $task, int $timestamp): array
    {
        $nonce = (string) ($this->nonceFactory)();
        if (!preg_match('/^[A-Za-z0-9_.:-]{16,128}$/', $nonce)) {
            throw new RuntimeException('回调 nonce 格式不合法');
        }
        $fields = [
            'source_bill_id' => (string) $task['source_bill_id'],
            'out_trade_no' => (string) $task['out_trade_no'],
            'money' => (string) $task['amount'],
            'occurred_at' => (string) $task['occurred_at'],
            'timestamp' => (string) $timestamp,
            'nonce' => $nonce,
        ];
        ksort($fields);
        $canonical = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $fields['sign'] = hash_hmac('sha256', $canonical, $this->callbackSecret);
        return $fields;
    }
}
