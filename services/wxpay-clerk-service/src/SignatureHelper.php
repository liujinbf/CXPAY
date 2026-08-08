<?php

declare(strict_types=1);

namespace WxpayClerk;

use RuntimeException;

/**
 * CXPAY 响应正文签名器；请求认证由 RequestAuthenticator 单独负责。
 */
final class SignatureHelper
{
    public function __construct(
        string $clientId,
        string $clientSecret,
        private readonly string $callbackSecret
    ) {
        if ($clientId === '') {
            throw new RuntimeException('client_id 不能为空');
        }
        if (strlen($clientSecret) < 32 || strlen($clientSecret) > 128) {
            throw new RuntimeException('client_secret 长度必须为 32 至 128 位');
        }
        if (strlen($callbackSecret) < 32 || strlen($callbackSecret) > 128) {
            throw new RuntimeException('callback_secret 长度必须为 32 至 128 位');
        }
    }

    public function signResponse(string $body): string
    {
        return hash_hmac('sha256', $body, $this->callbackSecret);
    }
}
