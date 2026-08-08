<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use DateTimeImmutable;
use CloudControl\Shared\Security\EncryptedSecret;

final class User
{
    private function __construct(
        private readonly string $id,
        private readonly EmailAddress $email,
        private ?string $displayName,
        private ?string $passwordHash,
        private UserStatus $status,
        private ?DateTimeImmutable $emailVerifiedAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private ?EncryptedSecret $totpSecret = null,
        private ?DateTimeImmutable $totpEnabledAt = null
    ) {
    }

    public static function pendingEmail(
        string $id,
        EmailAddress $email,
        DateTimeImmutable $now
    ): self {
        return new self(
            $id,
            $email,
            null,
            null,
            UserStatus::PENDING_EMAIL,
            null,
            $now,
            $now
        );
    }

    public static function reconstitute(
        string $id,
        EmailAddress $email,
        ?string $displayName,
        ?string $passwordHash,
        UserStatus $status,
        ?DateTimeImmutable $emailVerifiedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?EncryptedSecret $totpSecret = null,
        ?DateTimeImmutable $totpEnabledAt = null
    ): self {
        return new self(
            $id,
            $email,
            $displayName,
            $passwordHash,
            $status,
            $emailVerifiedAt,
            $createdAt,
            $updatedAt,
            $totpSecret,
            $totpEnabledAt
        );
    }

    public function completeEmailRegistration(
        string $displayName,
        string $passwordHash,
        DateTimeImmutable $now
    ): void {
        if ($this->status !== UserStatus::PENDING_EMAIL) {
            throw new CloudException(
                ErrorCode::REGISTRATION_INCOMPLETE,
                '当前账号不能完成邮箱注册',
                409
            );
        }

        $this->displayName = $displayName;
        $this->passwordHash = $passwordHash;
        $this->status = UserStatus::PENDING_IDENTITY;
        $this->emailVerifiedAt = $now;
        $this->updatedAt = $now;
    }

    public function activate(DateTimeImmutable $now): void
    {
        if ($this->status !== UserStatus::PENDING_IDENTITY) {
            throw new CloudException(
                ErrorCode::REGISTRATION_INCOMPLETE,
                '当前账号不能激活',
                409
            );
        }
        $this->status = UserStatus::ACTIVE;
        $this->updatedAt = $now;
    }

    public function enableTotp(EncryptedSecret $secret, DateTimeImmutable $now): void
    {
        $this->totpSecret = $secret;
        $this->totpEnabledAt = $now;
        $this->updatedAt = $now;
    }

    public function disableTotp(DateTimeImmutable $now): void
    {
        $this->totpSecret = null;
        $this->totpEnabledAt = null;
        $this->updatedAt = $now;
    }

    public function id(): string { return $this->id; }
    public function email(): EmailAddress { return $this->email; }
    public function emailCanonical(): string { return $this->email->canonical(); }
    public function displayName(): ?string { return $this->displayName; }
    public function passwordHash(): ?string { return $this->passwordHash; }
    public function status(): UserStatus { return $this->status; }
    public function emailVerifiedAt(): ?DateTimeImmutable { return $this->emailVerifiedAt; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function totpSecret(): ?EncryptedSecret { return $this->totpSecret; }
    public function totpEnabledAt(): ?DateTimeImmutable { return $this->totpEnabledAt; }
    public function totpEnabled(): bool { return $this->totpSecret !== null && $this->totpEnabledAt !== null; }
}
