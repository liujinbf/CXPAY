<?php

declare(strict_types=1);

namespace app\service;

use support\Response;

/**
 * Central policy for enabling or disabling destructive online-update actions.
 */
final class SystemUpdateGuard
{
    public function __construct(private readonly ?bool $enabled = null)
    {
    }

    public function isEnabled(): bool
    {
        return $this->enabled ?? (bool)config('app.system_update_enabled', false);
    }

    public function disabledResponse(): ?Response
    {
        if ($this->isEnabled()) {
            return null;
        }

        return json([
            'code' => 403,
            'msg' => '系统在线更新功能已禁用，请通过受控发布流程部署新版本',
            'data' => null,
        ])->withStatus(403);
    }
}
