<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;

final class PasswordPolicy
{
    private const MINIMUM_LENGTH = 12;
    private const MAXIMUM_LENGTH = 128;

    public function hash(string $password): string
    {
        $length = mb_strlen($password, 'UTF-8');
        if ($length < self::MINIMUM_LENGTH || $length > self::MAXIMUM_LENGTH) {
            throw new CloudException(
                ErrorCode::CREDENTIALS_INVALID,
                '密码长度必须为 12 至 128 个字符',
                422
            );
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if ($hash === false) {
            throw new CloudException(
                ErrorCode::INTERNAL_ERROR,
                '密码处理失败',
                500
            );
        }

        return $hash;
    }
}
