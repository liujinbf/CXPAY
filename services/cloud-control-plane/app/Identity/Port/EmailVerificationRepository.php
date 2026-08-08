<?php

declare(strict_types=1);

namespace CloudControl\Identity\Port;

use CloudControl\Identity\Domain\EmailVerification;
use CloudControl\Identity\Domain\EmailVerificationPurpose;

interface EmailVerificationRepository
{
    public function save(EmailVerification $verification): void;
    public function latestReadyForUpdate(
        string $emailCanonical,
        EmailVerificationPurpose $purpose
    ): ?EmailVerification;
}
