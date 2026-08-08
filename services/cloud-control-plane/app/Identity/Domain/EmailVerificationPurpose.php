<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

enum EmailVerificationPurpose: string
{
    case REGISTER = 'REGISTER';
}
