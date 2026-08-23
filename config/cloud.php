<?php

declare(strict_types=1);

return [
    'portal_url' => rtrim((string)env('CLOUD_PORTAL_URL', 'https://cloud.cxpay.com'), '/'),
    'api_url'    => rtrim((string)env('CLOUD_API_URL', 'https://api.cloud.cxpay.com'), '/'),
];

