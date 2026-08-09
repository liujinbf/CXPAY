<?php

declare(strict_types=1);

namespace Tests\Unit;

use app\middleware\AdminAssetCacheMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;
use Webman\Http\Response;

final class AdminAssetCacheMiddlewareTest extends TestCase
{
    public function testStaticConfigRegistersMiddleware(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/static.php';

        self::assertContains(AdminAssetCacheMiddleware::class, $config['middleware']);
    }

    #[DataProvider('revalidatedPathProvider')]
    public function testEntryAssetsRequireRevalidation(string $path): void
    {
        $response = $this->process($path);

        self::assertSame('no-cache, must-revalidate', $response->getHeader('Cache-Control'));
    }

    public function testOtherStaticAssetsKeepTheirExistingCachePolicy(): void
    {
        $response = $this->process('/admin/assets/ui.js');

        self::assertNull($response->getHeader('Cache-Control'));
    }

    /** @return iterable<string, array{string}> */
    public static function revalidatedPathProvider(): iterable
    {
        yield '管理页入口' => ['/admin/index.html'];
        yield '应用入口' => ['/admin/assets/app.js'];
        yield '版本模块' => ['/admin/assets/version.js'];
    }

    private function process(string $path): Response
    {
        $request = new Request("GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n");

        return (new AdminAssetCacheMiddleware())->process(
            $request,
            static fn (): Response => new Response(200, [], 'ok')
        );
    }
}
