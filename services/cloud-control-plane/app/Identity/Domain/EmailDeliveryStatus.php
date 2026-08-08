<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

enum EmailDeliveryStatus: string
{
    case PENDING_DELIVERY = 'PENDING_DELIVERY';
    case READY = 'READY';
    case INVALIDATED = 'INVALIDATED';
}
