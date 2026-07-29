<?php

declare(strict_types=1);

namespace WxMonitorCloud;

final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = ['Content-Type' => 'application/json; charset=utf-8'],
    ) {
    }

    /** @param array<string, mixed> $data @param array<string, string> $headers */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $headers + ['Content-Type' => 'application/json; charset=utf-8']
        );
    }
}
