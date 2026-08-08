<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

enum OAuthPurpose: string
{
    case REGISTER_BIND = 'REGISTER_BIND';
    case ACCOUNT_BIND = 'ACCOUNT_BIND';
    case LOGIN = 'LOGIN';
}
