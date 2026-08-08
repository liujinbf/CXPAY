<?php

declare(strict_types=1);

namespace CloudControl\Tests\Contract;

use CloudControl\Identity\Application\IdentityCompletion;
use CloudControl\Identity\Domain\OAuthAudience;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class IdentityCompletionContractTest extends TestCase
{
    public function testSerializesOnlyIdentityCompletionV1Fields(): void
    {
        $completion = new IdentityCompletion(
            '00000000-0000-7000-8000-000000000001',
            OAuthAudience::PORTAL,
            '00000000-0000-7000-8000-000000000002',
            false,
            new DateTimeImmutable('2026-08-09T08:00:00.123456+08:00')
        );

        self::assertSame([
            'version' => 'identity-completion-v1',
            'user_id' => '00000000-0000-7000-8000-000000000001',
            'audience' => 'PORTAL',
            'tenant_id' => '00000000-0000-7000-8000-000000000002',
            'totp_required' => false,
            'completed_at' => '2026-08-09T00:00:00.123456Z',
        ], $completion->toArray());
    }

    public function testOpsCompletionCannotBypassTotpRequirement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new IdentityCompletion(
            '00000000-0000-7000-8000-000000000001',
            OAuthAudience::OPS,
            null,
            false,
            new DateTimeImmutable('2026-08-09T00:00:00Z')
        );
    }

    public function testPortalLoginMayDeferTenantSelection(): void
    {
        $completion = new IdentityCompletion(
            '00000000-0000-7000-8000-000000000001',
            OAuthAudience::PORTAL,
            null,
            false,
            new DateTimeImmutable('2026-08-09T00:00:00Z')
        );

        self::assertNull($completion->toArray()['tenant_id']);
    }
}
