<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

final readonly class Totp
{
    public function __construct(
        public int $period = 30,
        public int $digits = 6,
        public string $algorithm = 'sha1'
    ) {
        if ($period <= 0 || $digits < 6 || $digits > 8 || !in_array($algorithm, ['sha1', 'sha256', 'sha512'], true)) {
            throw new \InvalidArgumentException('TOTP 参数无效');
        }
    }

    public function at(string $secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, $this->period);
        $high = intdiv($counter, 4294967296);
        $low = $counter % 4294967296;
        $hash = hash_hmac($this->algorithm, pack('N2', $high, $low), $secret, true);
        $offset = ord($hash[strlen($hash) - 1]) & 15;
        $binary = (
            ((ord($hash[$offset]) & 127) << 24)
            | ((ord($hash[$offset + 1]) & 255) << 16)
            | ((ord($hash[$offset + 2]) & 255) << 8)
            | (ord($hash[$offset + 3]) & 255)
        );
        $value = $binary % (10 ** $this->digits);

        return str_pad((string)$value, $this->digits, '0', STR_PAD_LEFT);
    }

    public function matchingStep(
        string $secret,
        string $code,
        int $timestamp,
        int $window = 1
    ): ?int {
        if (preg_match('/^\d{' . $this->digits . '}$/D', $code) !== 1) {
            return null;
        }
        $currentStep = intdiv($timestamp, $this->period);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $currentStep + $offset;
            if ($step >= 0 && hash_equals($this->at($secret, $step * $this->period), $code)) {
                return $step;
            }
        }
        return null;
    }

    public function requiredForTenantType(string $tenantType): bool
    {
        return $tenantType === 'OFFICIAL';
    }
}
