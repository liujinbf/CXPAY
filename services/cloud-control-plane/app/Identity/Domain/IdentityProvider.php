<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

enum IdentityProvider: string
{
    case QQ = 'QQ';
    case WECHAT = 'WECHAT';
}
