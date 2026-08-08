<?php

declare(strict_types=1);

namespace CloudControl\Shared\Security;

use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use InvalidArgumentException;
use Throwable;

final class SodiumSecretCipher implements SecretCipher
{
    private const ASSOCIATED_DATA = 'cxpay-cloud-totp-v1';

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidArgumentException('加密密钥必须为 32 字节');
        }
    }

    public function encrypt(string $plaintext): EncryptedSecret
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::ASSOCIATED_DATA,
            $nonce,
            $this->key
        );

        return new EncryptedSecret(
            self::base64UrlEncode($ciphertext),
            self::base64UrlEncode($nonce)
        );
    }

    public function decrypt(EncryptedSecret $secret): string
    {
        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                self::base64UrlDecode($secret->ciphertext),
                self::ASSOCIATED_DATA,
                self::base64UrlDecode($secret->nonce),
                $this->key
            );
        } catch (Throwable $exception) {
            throw self::decryptionFailed($exception);
        }

        if ($plaintext === false) {
            throw self::decryptionFailed();
        }

        return $plaintext;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('无效的 Base64URL 数据');
        }

        return $decoded;
    }

    private static function decryptionFailed(?Throwable $previous = null): CloudException
    {
        return new CloudException(
            ErrorCode::INTERNAL_ERROR,
            '安全数据无法解密',
            500,
            false,
            [],
            $previous
        );
    }
}
