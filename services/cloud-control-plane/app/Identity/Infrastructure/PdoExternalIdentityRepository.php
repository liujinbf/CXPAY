<?php

declare(strict_types=1);

namespace CloudControl\Identity\Infrastructure;

use CloudControl\Identity\Domain\ExternalIdentity;
use CloudControl\Identity\Port\ExternalIdentityRepository;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Shared\Id\IdGenerator;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

final readonly class PdoExternalIdentityRepository implements ExternalIdentityRepository
{
    public function __construct(
        private PDO $pdo,
        private IdGenerator $ids
    ) {
    }

    public function findUserId(ExternalIdentity $identity): ?string
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT user_id
FROM cloud_user_identities
WHERE provider = :provider AND issuer = :issuer AND subject = :subject
SQL);
        $statement->execute([
            'provider' => $identity->provider->value,
            'issuer' => $identity->issuer,
            'subject' => $identity->subject,
        ]);
        $userId = $statement->fetchColumn();

        return $userId === false ? null : (string)$userId;
    }

    public function bind(string $userId, ExternalIdentity $identity, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO cloud_user_identities (
    id, user_id, provider, issuer, subject, display_name,
    avatar_url, bound_at, created_at
) VALUES (
    :id, :user_id, :provider, :issuer, :subject, :display_name,
    :avatar_url, :bound_at, :created_at
)
SQL);
        try {
            $statement->execute([
                'id' => $this->ids->new(),
                'user_id' => $userId,
                'provider' => $identity->provider->value,
                'issuer' => $identity->issuer,
                'subject' => $identity->subject,
                'display_name' => $identity->displayName,
                'avatar_url' => $identity->avatarUrl,
                'bound_at' => self::format($now),
                'created_at' => self::format($now),
            ]);
        } catch (PDOException $exception) {
            if ((string)$exception->getCode() === '23000') {
                throw new CloudException(
                    ErrorCode::IDENTITY_ALREADY_BOUND,
                    '第三方身份已被绑定',
                    409,
                    false,
                    [],
                    $exception
                );
            }
            throw $exception;
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
