<?php

declare(strict_types=1);

namespace support;

use InvalidArgumentException;

/**
 * 授权账单源接口的输入规范化工具。
 */
final class BillSourceProtocol
{
    public const MAX_BATCH_SIZE = 100;
    public const MAX_AGE = 604800;
    public const FUTURE_TOLERANCE = 300;

    public static function bearerToken(string $authorization): ?string
    {
        if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,128})$/i', trim($authorization), $matches)) {
            return null;
        }
        return $matches[1];
    }

    public static function cursor(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_scalar($value) || !preg_match('/^\d{1,20}$/', (string)$value)) {
            throw new InvalidArgumentException('cursor 必须是非负整数');
        }
        $cursor = (int)$value;
        if ($cursor < 0) {
            throw new InvalidArgumentException('cursor 超出允许范围');
        }
        return $cursor;
    }

    public static function normalizeBill(array $params, string $channelPayType, ?int $now = null): array
    {
        $now ??= time();
        $sourceBillId = trim((string)($params['source_bill_id'] ?? ''));
        $payType = trim((string)($params['pay_type'] ?? ''));
        $moneyRaw = trim((string)($params['money'] ?? ''));
        $occurredAtRaw = $params['occurred_at'] ?? null;
        $remark = trim((string)($params['remark'] ?? ''));
        $collectorId = trim((string)($params['collector_id'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9_.:-]{16,128}$/', $sourceBillId)) {
            throw new InvalidArgumentException('source_bill_id 必须是16至128位稳定账单标识');
        }
        if (!in_array($payType, ['wxpay', 'alipay', 'qqpay'], true)
            || !hash_equals($channelPayType, $payType)) {
            throw new InvalidArgumentException('pay_type 与目标通道不一致');
        }
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $moneyRaw) || (float)$moneyRaw <= 0) {
            throw new InvalidArgumentException('money 必须是大于0且最多两位小数的金额');
        }
        if (!is_scalar($occurredAtRaw) || !preg_match('/^\d{10}$/', (string)$occurredAtRaw)) {
            throw new InvalidArgumentException('occurred_at 必须是秒级 Unix 时间戳');
        }
        $occurredAt = (int)$occurredAtRaw;
        if ($occurredAt < $now - self::MAX_AGE || $occurredAt > $now + self::FUTURE_TOLERANCE) {
            throw new InvalidArgumentException('账单发生时间无效或超出七天有效期');
        }
        if (mb_strlen($remark) > 255) {
            throw new InvalidArgumentException('remark 不能超过255个字符');
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $collectorId)) {
            throw new InvalidArgumentException('collector_id 格式不合法');
        }

        return [
            'source_bill_id' => $sourceBillId,
            'pay_type' => $payType,
            'money' => number_format((float)$moneyRaw, 2, '.', ''),
            'occurred_at' => $occurredAt,
            'remark' => $remark,
            'collector_id' => $collectorId,
        ];
    }
}
