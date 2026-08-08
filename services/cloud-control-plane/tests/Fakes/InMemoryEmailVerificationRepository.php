<?php

declare(strict_types=1);

namespace CloudControl\Tests\Fakes;

use CloudControl\Identity\Domain\EmailDeliveryStatus;
use CloudControl\Identity\Domain\EmailVerification;
use CloudControl\Identity\Domain\EmailVerificationPurpose;
use CloudControl\Identity\Port\EmailVerificationRepository;

final class InMemoryEmailVerificationRepository implements EmailVerificationRepository
{
    /** @var array<string, EmailVerification> */
    private array $records = [];

    public function save(EmailVerification $verification): void
    {
        $this->records[$verification->id()] = $verification;
    }

    public function latestReadyForUpdate(
        string $emailCanonical,
        EmailVerificationPurpose $purpose
    ): ?EmailVerification {
        $matches = array_filter(
            $this->records,
            static fn (EmailVerification $record): bool =>
                $record->emailCanonical() === $emailCanonical
                && $record->purpose() === $purpose
                && $record->deliveryStatus() === EmailDeliveryStatus::READY
                && $record->consumedAt() === null
        );
        usort(
            $matches,
            static fn (EmailVerification $left, EmailVerification $right): int =>
                $right->createdAt() <=> $left->createdAt()
        );

        return $matches[0] ?? null;
    }

    public function hasReadyCode(string $emailCanonical): bool
    {
        foreach ($this->records as $record) {
            if (
                $record->emailCanonical() === $emailCanonical
                && $record->deliveryStatus() === EmailDeliveryStatus::READY
                && $record->consumedAt() === null
            ) {
                return true;
            }
        }

        return false;
    }
}
