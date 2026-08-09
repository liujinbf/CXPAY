<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MerchantCenterModuleContractTest extends TestCase
{
    private const MERCHANT = __DIR__ . '/../../public/merchant';

    /**
     * @param list<string> $exports
     */
    #[DataProvider('coreModuleProvider')]
    public function testCoreModuleExistsAndExportsContract(string $file, array $exports): void
    {
        $path = self::MERCHANT . '/assets/' . $file;
        self::assertFileExists($path);

        $source = (string) file_get_contents($path);
        self::assertLessThanOrEqual(400, substr_count($source, "\n") + 1);
        foreach ($exports as $export) {
            self::assertStringContainsString('export ' . $export, $source);
        }
    }

    public function testCoreModulesKeepRuntimeBehavior(): void
    {
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

let redirected = null;
globalThis.window = {
    location: {
        origin: 'https://merchant.example.test',
        assign: (url) => { redirected = url; },
    },
};
globalThis.fetch = async () => ({ status: 401 });

const version = await import(pathToFileURL(process.argv[1]).href);
if (version.assetUrl('/merchant/assets/app.js') !== 'https://merchant.example.test/merchant/assets/app.js?v=merchant-modules-v1') {
    throw new Error('资源 URL 版本错误');
}

const ui = await import(pathToFileURL(process.argv[2]).href);
if (ui.escapeHtml(`<a data-x="'">&</a>`) !== '&lt;a data-x=&quot;&#39;&quot;&gt;&amp;&lt;/a&gt;') {
    throw new Error('HTML 转义错误');
}

const api = await import(pathToFileURL(process.argv[3]).href);
let message = null;
try {
    await api.merchantFetch('/api/merchant/profile');
} catch (error) {
    message = error.message;
}
if (redirected !== '/merchant_login.html' || message !== '商户登录状态已失效') {
    throw new Error('401 会话失效处理错误');
}
JS;

        [$exitCode, $output] = $this->runNode($script, [
            self::MERCHANT . '/assets/version.js',
            self::MERCHANT . '/assets/ui.js',
            self::MERCHANT . '/assets/api.js',
        ]);

        self::assertSame(0, $exitCode, $output);
    }

    /** @return iterable<string, array{0: string, 1: list<string>}> */
    public static function coreModuleProvider(): iterable
    {
        yield 'version' => ['version.js', ['const MERCHANT_ASSET_VERSION', 'function assetUrl']];
        yield 'api' => ['api.js', ['async function merchantFetch']];
        yield 'ui' => ['ui.js', [
            'function escapeHtml',
            'function safeCreateIcons',
            'function showToast',
            'function showConfirm',
            'async function copyText',
        ]];
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string}
     */
    private function runNode(string $script, array $arguments): array
    {
        $pipes = [];
        $process = proc_open(
            ['node', '--input-type=module', '--eval', $script, ...$arguments],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), trim((string) $stdout . PHP_EOL . (string) $stderr)];
    }
}
