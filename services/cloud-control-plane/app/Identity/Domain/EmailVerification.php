<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use DateTimeImmutable;

final class EmailVerification
{
    public function __construct(
        private readonly string $id,
        private readonly string $emailCanonical,
        private readonly EmailVerificationPurpose $purpose,
        private EmailDeliveryStatus $deliveryStatus,
        private readonly string $codeDigest,
        private int $attempts,
        private readonly DateTimeImmutable $expiresAt,
        private ?DateTimeImmutable $consumedAt,
        private readonly string $requestedIp,
        private readonly DateTimeImmutable $createdAt
    ) {
    }

    public static function pendingDelivery(
        string $id,
        string $emailCanonical,
        string $codeDigest,
        DateTimeImmutable $expiresAt,
        string $requestedIp,
        DateTimeImmutable $createdAt
    ): self {
        return new self(
            $id,
            $emailCanonical,
            EmailVerificationPurpose::REGISTER,
            EmailDeliveryStatus::PENDING_DELIVERY,
            $codeDigest,
            0,
            $expiresAt,
            null,
            $requestedIp,
            $createdAt
        );
    }

    public function markReady(): void
    {
        if ($this->deliveryStatus !== EmailDeliveryStatus::PENDING_DELIVERY) {
            throw new \LogicException('只有待投递验证码可以标记为可用');
        }
        $this->deliveryStatus = EmailDeliveryStatus::READY;
    }

    public function invalidate(): void
    {
        $this->deliveryStatus = EmailDeliveryStatus::INVALIDATED;
    }

    public function verifyDigest(string $candidateDigest, DateTimeImmutable $now): bool
    {
        if ($this->deliveryStatus !== EmailDeliveryStatus::READY || $this->consumedAt !== null) {
            throw self::invalidCode();
        }
        if ($now >= $this->expiresAt) {
            throw new CloudException(
                ErrorCode::EMAIL_CODE_EXPIRED,
                '邮箱验证码已过期',
                422
            );
        }
        if ($this->attempts >= 5) {
            throw self::invalidCode();
        }
        if (!hash_equals($this->codeDigest, $candidateDigest)) {
            $this->attempts++;
            return false;
        }

        $this->consumedAt = $now;
        return true;
    }

    public function id(): string { return $this->id; }
    public function emailCanonical(): string { return $this->emailCanonical; }
    public function purpose(): EmailVerificationPurpose { return $this->purpose; }
    public function deliveryStatus(): EmailDeliveryStatus { return $this->deliveryStatus; }
    public function codeDigest(): string { return $this->codeDigest; }
    public function attempts(): int { return $this->attempts; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function consumedAt(): ?DateTimeImmutable { return $this->consumedAt; }
    public function requestedIp(): string { return $this->requestedIp; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    private static function invalidCode(): CloudException
    {
        return new CloudException(
            ErrorCode::EMAIL_CODE_INVALID,
            '邮箱验证码无效',
            422
        );
    }
}
