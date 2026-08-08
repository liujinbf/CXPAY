<?php

declare(strict_types=1);

namespace CloudControl\Tests\Unit;

use CloudControl\Shared\Http\HealthController;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testHealthResponseIdentifiesOnlyCloudControlService(): void
    {
        $response = (new HealthController())();
        $payload = json_decode($response->rawBody(), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['code']);
        self::assertSame('ok', $payload['data']['status']);
        self::assertSame('cloud-control-plane', $payload['data']['service']);
    }
}
