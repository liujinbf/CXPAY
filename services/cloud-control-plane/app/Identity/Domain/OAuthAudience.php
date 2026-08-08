<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

enum OAuthAudience: string
{
    case PORTAL = 'PORTAL';
    case OPS = 'OPS';
}
