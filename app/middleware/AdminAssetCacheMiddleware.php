<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class AdminAssetCacheMiddleware implements MiddlewareInterface
{
    private const REVALIDATED_PATHS = [
        'admin/index.html',
        'admin/assets/app.js',
        'admin/assets/version.js',
    ];

    public function process(Request $request, callable $handler): Response
    {
        $response = $handler($request);
        if (in_array(ltrim($request->path(), '/'), self::REVALIDATED_PATHS, true)) {
            $response->withHeader('Cache-Control', 'no-cache, must-revalidate');
        }

        return $response;
    }
}
