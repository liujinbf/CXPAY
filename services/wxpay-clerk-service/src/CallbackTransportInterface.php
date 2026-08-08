<?php

declare(strict_types=1);

namespace WxpayClerk;

interface CallbackTransportInterface
{
    /** @param array<string, string> $fields @return array{status: int, body: string} */
    public function post(string $url, array $fields): array;
}
