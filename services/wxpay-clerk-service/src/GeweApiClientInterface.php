<?php

declare(strict_types=1);

namespace WxpayClerk;

interface GeweApiClientInterface
{
    /** @return array{appid: string, qr_url: string, uuid: string} */
    public function createLoginSession(): array;

    /** @return array{status: string, wxid?: string, nickname?: string} */
    public function checkLoginStatus(string $appId, string $uuid): array;

    /** @return array{online: bool, nickname: string} */
    public function getAccountStatus(string $appId): array;

    public function setCallback(string $appId, string $callbackUrl): void;
}
