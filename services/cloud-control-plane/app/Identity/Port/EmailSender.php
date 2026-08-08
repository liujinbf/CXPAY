<?php

declare(strict_types=1);

namespace CloudControl\Identity\Port;

use CloudControl\Identity\Domain\EmailAddress;

interface EmailSender
{
    public function sendVerificationCode(EmailAddress $email, string $code): void;
}
