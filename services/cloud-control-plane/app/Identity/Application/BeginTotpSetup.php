<?php

declare(strict_types=1);

namespace CloudControl\Identity\Application;

use CloudControl\Identity\Domain\PendingTotpSetup;
use CloudControl\Identity\Port\TotpSetupStore;
use CloudControl\Identity\Port\UserRepository;
use CloudControl\Shared\Clock\Clock;
use CloudControl\Shared\Error\CloudException;
use CloudControl\Shared\Error\ErrorCode;
use CloudControl\Shared\Security\Base32;

final readonly class BeginTotpSetup
{
    public function __construct(
        private UserRepository $users,
        private TotpSetupStore $setups,
        private Clock $clock
    ) {
    }

    public function handle(string $userId, string $issuer, string $account): TotpSetupView
    {
        if ($this->users->findById($userId) === null) {
            throw new CloudException(ErrorCode::REGISTRATION_INCOMPLETE, '用户不存在', 404);
        }
        $secret = Base32::encodeUnpadded(random_bytes(20));
        $expiresAt = $this->clock->now()->modify('+10 minutes');
        $this->setups->save(new PendingTotpSetup($userId, $secret, $expiresAt));
        $label = rawurlencode($issuer . ':' . $account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);

        return new TotpSetupView('otpauth://totp/' . $label . '?' . $query, $expiresAt);
    }
}
