<?php

declare(strict_types=1);

return [
    'role' => strtolower(trim((string)env('CXPAY_RUNTIME_ROLE', 'payment'))),
];
