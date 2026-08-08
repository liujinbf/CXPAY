<?php

declare(strict_types=1);

namespace CloudControl\Identity\Infrastructure;

use CloudControl\Identity\Domain\EmailAddress;
use CloudControl\Identity\Domain\User;
use CloudControl\Identity\Domain\UserStatus;
use CloudControl\Identity\Port\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final readonly class PdoUserRepository implements UserRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findOrCreatePending(User $candidate): User
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT IGNORE INTO cloud_users (
    id, email, email_canonical, status, created_at, updated_at
) VALUES (
    :id, :email, :email_canonical, :status, :created_at, :updated_at
)
SQL);
        $statement->execute([
            'id' => $candidate->id(),
            'email' => $candidate->email()->display(),
            'email_canonical' => $candidate->emailCanonical(),
            'status' => $candidate->status()->value,
            'created_at' => self::format($candidate->createdAt()),
            'updated_at' => self::format($candidate->updatedAt()),
        ]);

        $user = $this->findByEmail($candidate->emailCanonical(), true);
        if ($user === null) {
            throw new RuntimeException('邮箱用户创建失败');
        }

        return $user;
    }

    public function findByEmailCanonicalForUpdate(string $emailCanonical): ?User
    {
        return $this->findByEmail($emailCanonical, true);
    }

    public function findById(string $id): ?User
    {
        return $this->findByIdInternal($id, false);
    }

    public function findByIdForUpdate(string $id): ?User
    {
        return $this->findByIdInternal($id, true);
    }

    public function save(User $user): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE cloud_users
SET display_name = :display_name,
    password_hash = :password_hash,
    status = :status,
    email_verified_at = :email_verified_at,
    updated_at = :updated_at
WHERE id = :id
SQL);
        $statement->execute([
            'display_name' => $user->displayName(),
            'password_hash' => $user->passwordHash(),
            'status' => $user->status()->value,
            'email_verified_at' => self::formatNullable($user->emailVerifiedAt()),
            'updated_at' => self::format($user->updatedAt()),
            'id' => $user->id(),
        ]);
    }

    private function findByEmail(string $emailCanonical, bool $forUpdate): ?User
    {
        $sql = <<<'SQL'
SELECT id, email, display_name, password_hash, status,
       email_verified_at, created_at, updated_at
FROM cloud_users
WHERE email_canonical = :email_canonical
SQL;
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['email_canonical' => $emailCanonical]);
        $row = $statement->fetch();

        return $row === false ? null : self::map($row);
    }

    private function findByIdInternal(string $id, bool $forUpdate): ?User
    {
        $sql = <<<'SQL'
SELECT id, email, display_name, password_hash, status,
       email_verified_at, created_at, updated_at
FROM cloud_users
WHERE id = :id
SQL;
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row === false ? null : self::map($row);
    }

    /** @param array<string, mixed> $row */
    private static function map(array $row): User
    {
        return User::reconstitute(
            (string)$row['id'],
            EmailAddress::fromString((string)$row['email']),
            $row['display_name'] === null ? null : (string)$row['display_name'],
            $row['password_hash'] === null ? null : (string)$row['password_hash'],
            UserStatus::from((string)$row['status']),
            self::dateNullable($row['email_verified_at']),
            self::date((string)$row['created_at']),
            self::date((string)$row['updated_at'])
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
