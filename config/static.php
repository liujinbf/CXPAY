<?php

return [
    'enable' => true,
    'middleware' => [
        app\middleware\AdminAssetCacheMiddleware::class,
        app\middleware\MerchantAssetCacheMiddleware::class,
    ],
];
