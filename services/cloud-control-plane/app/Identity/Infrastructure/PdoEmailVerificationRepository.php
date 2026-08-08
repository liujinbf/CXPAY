<?php

declare(strict_types=1);

namespace CloudControl\Identity\Infrastructure;

use CloudControl\Identity\Domain\EmailDeliveryStatus;
use CloudControl\Identity\Domain\EmailVerification;
use CloudControl\Identity\Domain\EmailVerificationPurpose;
use CloudControl\Identity\Port\EmailVerificationRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final readonly class PdoEmailVerificationRepository implements EmailVerificationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(EmailVerification $verification): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cloud_email_verifications (
    id, email_canonical, purpose, delivery_status, code_digest, attempts,
    expires_at, consumed_at, requested_ip, created_at
) VALUES (
    :id, :email_canonical, :purpose, :delivery_status, :code_digest, :attempts,
    :expires_at, :consumed_at, :requested_ip, :created_at
)
ON DUPLICATE KEY UPDATE
    delivery_status = VALUES(delivery_status),
    attempts = VALUES(attempts),
    consumed_at = VALUES(consumed_at)
SQL);
        $statement->execute([
            'id' => $verification->id(),
            'email_canonical' => $verification->emailCanonical(),
            'purpose' => $verification->purpose()->value,
            'delivery_status' => $verification->deliveryStatus()->value,
            'code_digest' => $verification->codeDigest(),
            'attempts' => $verification->attempts(),
            'expires_at' => self::format($verification->expiresAt()),
            'consumed_at' => self::formatNullable($verification->consumedAt()),
            'requested_ip' => $verification->requestedIp(),
            'created_at' => self::format($verification->createdAt()),
        ]);
    }

    public function latestReadyForUpdate(
        string $emailCanonical,
        EmailVerificationPurpose $purpose
    ): ?EmailVerification {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT *
FROM cloud_email_verifications
WHERE email_canonical = :email_canonical
  AND purpose = :purpose
  AND delivery_status = 'READY'
  AND consumed_at IS NULL
ORDER BY created_at DESC
LIMIT 1
FOR UPDATE
SQL);
        $statement->execute([
            'email_canonical' => $emailCanonical,
            'purpose' => $purpose->value,
        ]);
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        return new EmailVerification(
            (string)$row['id'],
            (string)$row['email_canonical'],
            EmailVerificationPurpose::from((string)$row['purpose']),
            EmailDeliveryStatus::from((string)$row['delivery_status']),
            (string)$row['code_digest'],
            (int)$row['attempts'],
            self::date((string)$row['expires_at']),
            self::dateNullable($row['consumed_at']),
            (string)$row['requested_ip'],
            self::date((string)$row['created_at'])
        );
    }

    private static function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private static function dateNullable(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : self::date((string)$value);
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function formatNullable(?DateTimeImmutable $value): ?string
    {
        return $value === null ? null : self::format($value);
    }
}
