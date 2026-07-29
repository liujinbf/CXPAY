<?php

declare(strict_types=1);

namespace WxCollector;

/** 云端生成正式账号 ID 后，将本地授权凭据与该账号绑定。 */
interface AccountBindingAwareInterface
{
    public function bindAuthorizedAccount(string $sessionId, string $accountId): void;
}
