<?php

declare(strict_types=1);

namespace CloudControl\Identity\Domain;

final readonly class ExternalIdentity
{
    public function __construct(
        public IdentityProvider $provider,
        public string $issuer,
        public string $subject,
        public string $displayName,
        public ?string $avatarUrl
    ) {
        if ($issuer === '' || $subject === '') {
            throw new \InvalidArgumentException('第三方身份 issuer 和 subject 不能为空');
        }
    }

    public function key(): string
    {
        return $this->provider->value . "\n" . $this->issuer . "\n" . $this->subject;
    }
}
