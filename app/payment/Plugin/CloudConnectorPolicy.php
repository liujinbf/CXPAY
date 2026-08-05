<?php

declare(strict_types=1);

namespace app\payment\Plugin;

final class CloudConnectorPolicy
{
    /** @var list<string> */
    private const FORBIDDEN = [
        'cookie',
        'set-cookie',
        'browser_cookie',
        'session_token',
        'login_token',
        'web_token',
        'device_token',
        'browser_storage',
        'local_storage',
        'session_storage',
        'web_session',
    ];

    public function assertManifest(PluginManifest $manifest): void
    {
        if ($manifest->runtimeType() !== 'cloud_connector') {
            return;
        }
        if ($manifest->credentialBoundary() !== 'cloud_only') {
            throw new PluginException('云端连接器必须声明 cloud_only 凭据边界');
        }

        $permissions = $manifest->permissions();
        $hosts = $permissions['outbound_hosts'] ?? null;
        if (!is_array($hosts) || $hosts === []) {
            throw new PluginException('云端连接器必须声明精确 outbound_hosts');
        }
        foreach ($hosts as $host) {
            if (!is_string($host)
                || filter_var($host, FILTER_VALIDATE_IP)
                || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $host)) {
                throw new PluginException('云端连接器 outbound_hosts 包含非法主机名');
            }
        }

        foreach ((array)($permissions['secret_config'] ?? []) as $name) {
            $this->assertSafeText((string)$name);
        }
    }

    /** @param array<string, mixed> $meta */
    public function assertDriverMeta(PluginManifest $manifest, array $meta): void
    {
        if ($manifest->runtimeType() !== 'cloud_connector') {
            return;
        }
        foreach ((array)($meta['inputs'] ?? []) as $input) {
            if (!is_array($input)) {
                continue;
            }
            $this->assertSafeText(implode(' ', [
                (string)($input['name'] ?? ''),
                (string)($input['title'] ?? ''),
                (string)($input['content'] ?? ''),
            ]));
        }
    }

    private function assertSafeText(string $value): void
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $value));
        foreach (self::FORBIDDEN as $forbidden) {
            $needle = str_replace('-', '_', $forbidden);
            if (str_contains($normalized, $needle)) {
                throw new PluginException('云端连接器禁止声明或接收 Cookie/网页登录凭据');
            }
        }
    }
}
