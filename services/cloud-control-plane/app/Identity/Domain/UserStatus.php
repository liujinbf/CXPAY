<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

enum UserStatus: string
{
    case PENDING_EMAIL = 'PENDING_EMAIL';
    case PENDING_IDENTITY = 'PENDING_IDENTITY';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
}
