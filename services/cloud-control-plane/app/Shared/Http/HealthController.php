<?php

declare(strict_types=1);

namespace CloudControl\Shared\Http;

use support\Response;

final class HealthController
{
    public function __invoke(): Response
    {
        return json([
            'code' => 1,
            'data' => [
                'status' => 'ok',
                'service' => 'cloud-control-plane',
            ],
        ]);
    }
}
