<?php

declare(strict_types=1);

namespace CloudControl\Identity\Port;

use CloudControl\Identity\Domain\ExternalIdentity;
use DateTimeImmutable;

interface ExternalIdentityRepository
{
    public function findUserId(ExternalIdentity $identity): ?string;
    public function bind(string $userId, ExternalIdentity $identity, DateTimeImmutable $now): void;
}
