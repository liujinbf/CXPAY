<?php

declare(strict_types=1);

namespace CloudControl\Shared\Security;

final readonly class EncryptedSecret
{
    public function __construct(
        public string $ciphertext,
        public string $nonce
    ) {
    }
}
