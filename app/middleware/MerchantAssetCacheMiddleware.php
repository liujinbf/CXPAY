<?php

declare(strict_types=1);

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class MerchantAssetCacheMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $response = $handler($request);
        $path = ltrim($request->path(), '/');
        if (
            str_ends_with($path, '.html') ||
            str_ends_with($path, '.js') ||
            str_starts_with($path, 'merchant/') ||
            str_starts_with($path, 'admin/')
        ) {
            $response->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                     ->withHeader('Pragma', 'no-cache')
                     ->withHeader('Expires', '0');
        }

        return $response;
    }
}
