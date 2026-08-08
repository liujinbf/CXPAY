<?php

declare(strict_types=1);

use CloudControl\Shared\Security\Base64UrlKey;
use CloudControl\Shared\Config\Environment;

return [
    'email_code_pepper' => Base64UrlKey::decode(
        (string)Environment::get('CLOUD_EMAIL_CODE_PEPPER', ''),
        'CLOUD_EMAIL_CODE_PEPPER'
    ),
    'oauth_state_hmac_key' => Base64UrlKey::decode(
        (string)Environment::get('CLOUD_OAUTH_STATE_HMAC_KEY', ''),
        'CLOUD_OAUTH_STATE_HMAC_KEY'
    ),
    'totp_encryption_key' => Base64UrlKey::decode(
        (string)Environment::get('CLOUD_TOTP_ENCRYPTION_KEY', ''),
        'CLOUD_TOTP_ENCRYPTION_KEY'
    ),
];
