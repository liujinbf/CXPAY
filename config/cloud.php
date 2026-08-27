<?php

declare(strict_types=1);

return [
    'portal_url' => rtrim((string)env('CLOUD_PORTAL_URL', 'https://cloud.fcwan.cn'), '/'),
    'api_url'    => rtrim((string)env('CLOUD_API_URL', 'https://cloud.fcwan.cn'), '/'),
];

