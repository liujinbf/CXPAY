<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\middleware\MerchantAssetCacheMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;
use Webman\Http\Response;

final class MerchantAssetCacheMiddlewareTest extends TestCase
{
    public function testStaticConfigRegistersMiddleware(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/static.php';

        self::assertContains(MerchantAssetCacheMiddleware::class, $config['middleware']);
    }

    #[DataProvider('revalidatedPathProvider')]
    public function testEntryAssetsRequireRevalidation(string $path): void
    {
        $response = $this->process($path);

        self::assertSame('no-cache, must-revalidate', $response->getHeader('Cache-Control'));
    }

    public function testOtherMerchantAssetsKeepExistingCachePolicy(): void
    {
        $response = $this->process('/merchant/views/dashboard.html');

        self::assertNull($response->getHeader('Cache-Control'));
    }

    /** @return iterable<string, array{string}> */
    public static function revalidatedPathProvider(): iterable
    {
        yield '商户中心入口' => ['/merchant_center.html'];
        yield '商户应用入口' => ['/merchant/assets/app.js'];
        yield '商户版本模块' => ['/merchant/assets/version.js'];
    }

    private function process(string $path): Response
    {
        $request = new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");

        return (new MerchantAssetCacheMiddleware())->process(
            $request,
            static fn (): Response => new Response(200, [], 'ok')
        );
    }
}
