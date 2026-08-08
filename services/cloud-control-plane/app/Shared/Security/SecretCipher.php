<?php

declare(strict_types=1);

namespace CloudControl\Shared\Security;

interface SecretCipher
{
    public function encrypt(string $plaintext): EncryptedSecret;

    public function decrypt(EncryptedSecret $secret): string;
}
