<?php

declare(strict_types=1);

namespace CloudControl\Shared\Id;

interface IdGenerator
{
    public function new(): string;
}
