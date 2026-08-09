<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MerchantCenterModuleContractTest extends TestCase
{
    private const MERCHANT = __DIR__ . '/../../public/merchant';
    private const MERCHANT_CENTER = __DIR__ . '/../../public/merchant_center.html';

    public function testPageLoadsOneMerchantApplicationEntry(): void
    {
        $html = (string) file_get_contents(self::MERCHANT_CENTER);

        self::assertSame(1, substr_count($html, 'type="module" src="/merchant/assets/app.js"'));
        self::assertFileExists(self::MERCHANT . '/assets/app.js');
        self::assertFileExists(self::MERCHANT . '/assets/router.js');
    }

    public function testRouterKeepsKnownFeaturesAndFallsBackToDashboard(): void
    {
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

globalThis.window = { location: { origin: 'https://merchant.example.test' } };
const { resolveFeatureId } = await import(pathToFileURL(process.argv[1]).href);
const definitions = new Map([['dashboard', {}], ['order-list', {}]]);
const knownIds = new Set(['dashboard', 'profile', 'order-list']);
if (resolveFeatureId('order-list', definitions, knownIds) !== 'order-list') {
    throw new Error('已注册标签未保持原 ID');
}
if (resolveFeatureId('profile', definitions, knownIds) !== 'profile') {
    throw new Error('未迁移的已知标签未保持原 ID');
}
if (resolveFeatureId('unknown', definitions, knownIds) !== 'dashboard') {
    throw new Error('未知标签未回退首页');
}
JS;

        [$exitCode, $output] = $this->runNode($script, [self::MERCHANT . '/assets/router.js']);

        self::assertSame(0, $exitCode, $output);
    }

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
if (version.assetUrl('/merchant/assets/app.js') !== 'https://merchant.example.test/merchant/assets/app.js?v=merchant-modules-v2') {
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

    #[DataProvider('identityFeatureProvider')]
    public function testIdentityFeaturePairExists(string $file, string $feature): void
    {
        $view = self::MERCHANT . '/views/' . $file . '.html';
        $module = self::MERCHANT . '/assets/features/' . $file . '.js';

        self::assertFileExists($view);
        self::assertFileExists($module);
        self::assertStringContainsString(
            'data-feature="' . $feature . '"',
            (string) file_get_contents($view)
        );
        self::assertStringContainsString('export const feature', (string) file_get_contents($module));
    }

    public function testIdentityFeaturesKeepMerchantApiContracts(): void
    {
        $dashboard = (string) file_get_contents(self::MERCHANT . '/assets/features/dashboard.js');
        $profile = (string) file_get_contents(self::MERCHANT . '/assets/features/profile.js');
        $apiKeys = (string) file_get_contents(self::MERCHANT . '/assets/features/api-keys.js');

        foreach ([
            '/api/merchant/dashboard',
            '/api/merchant/order/list?page_size=5',
            '/api/merchant/order/list?page_size=200&status=1',
        ] as $api) {
            self::assertStringContainsString($api, $dashboard);
        }
        self::assertStringContainsString('/api/merchant/change_password', $profile);
        self::assertStringContainsString('/api/merchant/reset_key', $apiKeys);
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

    /** @return iterable<string, array{string, string}> */
    public static function identityFeatureProvider(): iterable
    {
        yield '仪表盘' => ['dashboard', 'dashboard'];
        yield '账户设置' => ['profile', 'profile'];
        yield 'API 密钥' => ['api-keys', 'api-keys'];
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
