<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\OAuthAudience;
use DateTimeImmutable;
use DateTimeZone;

final readonly class IdentityCompletion
{
    public const VERSION = 'identity-completion-v1';

    public function __construct(
        public string $userId,
        public OAuthAudience $audience,
        public ?string $tenantId,
        public bool $totpRequired,
        public DateTimeImmutable $completedAt
    ) {
        if ($audience === OAuthAudience::OPS && !$totpRequired) {
            throw new \InvalidArgumentException('Ops completion 必须要求 TOTP');
        }
    }

    /** @return array{version: string, user_id: string, audience: string, tenant_id: ?string, totp_required: bool, completed_at: string} */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'user_id' => $this->userId,
            'audience' => $this->audience->value,
            'tenant_id' => $this->tenantId,
            'totp_required' => $this->totpRequired,
            'completed_at' => $this->completedAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
