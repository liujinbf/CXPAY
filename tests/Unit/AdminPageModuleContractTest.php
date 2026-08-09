<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminPageModuleContractTest extends TestCase
{
    private const ADMIN = __DIR__ . '/../../public/admin';

    public function testFinalAdminShellIsSmallAndContainsNoInlineBusinessScript(): void
    {
        $html = (string) file_get_contents(self::ADMIN . '/index.html');
        self::assertLessThanOrEqual(500, substr_count($html, "\n") + 1);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/si', $html);
        self::assertStringNotContainsString('onclick=', $html);
    }

    public function testAllAdminFeaturesStayWithinSizeLimit(): void
    {
        foreach (glob(self::ADMIN . '/{assets/features,views}/*', GLOB_BRACE) ?: [] as $path) {
            $source = (string) file_get_contents($path);
            self::assertLessThanOrEqual(400, substr_count($source, "\n") + 1, $path);
        }
    }

    /**
     * @param list<string> $exports
     */
    #[DataProvider('coreModuleProvider')]
    public function testCoreModuleExistsAndIsFocused(string $file, array $exports): void
    {
        $path = self::ADMIN . '/assets/' . $file;
        self::assertFileExists($path);

        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertLessThanOrEqual(400, substr_count($source, "\n") + 1);
        foreach ($exports as $export) {
            self::assertStringContainsString('export ' . $export, $source);
        }
    }

    public function testApplicationNamespaceOwnsPublicCompatibilitySurface(): void
    {
        $html = file_get_contents(self::ADMIN . '/index.html');
        self::assertIsString($html);
        self::assertStringNotContainsString('function adminFetch(', $html);
        self::assertStringNotContainsString('function escapeHtml(', $html);
        self::assertStringNotContainsString('function showCustomConfirm(', $html);

        $app = file_get_contents(self::ADMIN . '/assets/app.js');
        self::assertIsString($app);
        self::assertStringContainsString(
            'window.CXAdmin = Object.freeze({ api, ui, navigate: router.navigate });',
            $app
        );
    }

    public function testAdminPageLoadsOneApplicationEntry(): void
    {
        $html = file_get_contents(self::ADMIN . '/index.html');
        self::assertIsString($html);
        self::assertSame(1, substr_count($html, 'type="module" src="/admin/assets/app.js"'));
        self::assertFileExists(self::ADMIN . '/assets/router.js');
        self::assertFileExists(self::ADMIN . '/assets/app.js');

        $app = file_get_contents(self::ADMIN . '/assets/app.js');
        self::assertIsString($app);
        self::assertStringContainsString("import(assetUrl('/admin/assets/api.js'))", $app);
        self::assertStringContainsString("import(assetUrl('/admin/assets/ui.js'))", $app);
        self::assertStringContainsString("import(assetUrl('/admin/assets/router.js'))", $app);
    }

    public function testRouterFallsBackToDashboardForUnknownFeatures(): void
    {
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

globalThis.window = { location: { origin: 'https://admin.example.test' } };
const { resolveFeatureId } = await import(pathToFileURL(process.argv[1]).href);
const definitions = new Map([['dashboard', {}], ['orders', {}]]);
if (resolveFeatureId('orders', definitions) !== 'orders') {
    throw new Error('已注册功能未按原 ID 路由');
}
if (resolveFeatureId('unknown', definitions) !== 'dashboard') {
    throw new Error('未知功能没有回退到仪表盘');
}
JS;

        [$exitCode, $output] = $this->runNode($script, [self::ADMIN . '/assets/router.js']);

        self::assertSame(0, $exitCode, $output);
    }

    public function testApplicationOwnsInitialNavigationAfterNamespaceIsReady(): void
    {
        $html = file_get_contents(self::ADMIN . '/index.html');
        $app = file_get_contents(self::ADMIN . '/assets/app.js');
        self::assertIsString($html);
        self::assertIsString($app);
        self::assertStringNotContainsString("document.addEventListener('DOMContentLoaded'", $html);
        self::assertStringNotContainsString('CXAdminPendingTab', $html);
        self::assertStringNotContainsString('CXAdminPendingTab', $app);
        self::assertMatchesRegularExpression(
            '/window\.CXAdmin\s*=.*?;[\s\S]+?router\.navigate\(initialTab\);/',
            $app
        );
    }

    #[DataProvider('firstFeatureProvider')]
    public function testFirstFeatureHasFocusedViewAndModule(string $id): void
    {
        $viewPath = self::ADMIN . '/views/' . $id . '.html';
        $modulePath = self::ADMIN . '/assets/features/' . $id . '.js';
        self::assertFileExists($viewPath);
        self::assertFileExists($modulePath);

        $view = file_get_contents($viewPath);
        $module = file_get_contents($modulePath);
        self::assertIsString($view);
        self::assertIsString($module);
        self::assertStringContainsString('data-feature="' . $id . '"', $view);
        self::assertStringNotContainsString('onclick=', $view);
        self::assertStringContainsString('export const feature', $module);
        self::assertLessThanOrEqual(400, substr_count($module, "\n") + 1);
    }

    public function testFirstFeaturesAreRegisteredAndRemovedFromLegacyPage(): void
    {
        $html = file_get_contents(self::ADMIN . '/index.html');
        $app = file_get_contents(self::ADMIN . '/assets/app.js');
        self::assertIsString($html);
        self::assertIsString($app);

        foreach (self::firstFeatureIds() as $id) {
            self::assertStringContainsString(
                "definitions.set('{$id}', { view: '{$id}.html', module: '{$id}.js' });",
                $app
            );
            self::assertStringNotContainsString('id="tab-' . $id . '"', $html);
        }
        self::assertStringNotContainsString('id="tab-system-update-legacy"', $html);
        foreach ([
            'loadDashboard',
            'loadDashboardRecentOrders',
            'initAdminTrendChart',
            'checkGitUpdate',
            'executeGitUpdate',
            'loadGitVersionHistory',
            'checkSystemUpdate',
            'doSystemUpdate',
            'loadVersionHistory',
            'loadCloudMonitorStatus',
        ] as $function) {
            self::assertStringNotContainsString('function ' . $function . '(', $html);
        }
    }

    #[DataProvider('commerceFeatureProvider')]
    public function testCommerceFeatureHasViewAndModule(string $id): void
    {
        $view = self::ADMIN . '/views/' . $id . '.html';
        $module = self::ADMIN . '/assets/features/' . $id . '.js';
        self::assertFileExists($view);
        self::assertFileExists($module);
        self::assertStringContainsString('data-feature="' . $id . '"', (string) file_get_contents($view));
        self::assertStringContainsString('export const feature', (string) file_get_contents($module));
    }

    public function testCommerceFeaturesReplaceAllLegacyImplementations(): void
    {
        $html = (string) file_get_contents(self::ADMIN . '/index.html');
        $app = (string) file_get_contents(self::ADMIN . '/assets/app.js');
        self::assertStringContainsString(
            "definitions.set('channel-config', { view: 'channels.html', module: 'channels.js' });",
            $app
        );
        self::assertStringContainsString(
            "definitions.set('plugin-market', { view: 'plugins.html', module: 'plugins.js' });",
            $app
        );
        foreach (['tab-channel-config', 'tab-plugin-market', 'channel-config-editor'] as $id) {
            self::assertStringNotContainsString('id="' . $id . '"', $html);
        }
        foreach (['loadAdminChannels', 'loadInstalledPlugins', 'loadPluginMarket', 'editAdminChannel'] as $function) {
            self::assertStringNotContainsString('function ' . $function . '(', $html);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function commerceFeatureProvider(): iterable
    {
        yield 'channels' => ['channels'];
        yield 'plugins' => ['plugins'];
    }

    #[DataProvider('accountFeatureProvider')]
    public function testAccountFeatureKeepsApiAndEditorContract(
        string $id,
        string $apiPrefix,
        string $editorId
    ): void {
        $viewPath = self::ADMIN . '/views/' . $id . '.html';
        $modulePath = self::ADMIN . '/assets/features/' . $id . '.js';
        self::assertFileExists($viewPath);
        self::assertFileExists($modulePath);
        self::assertStringContainsString('id="' . $editorId . '"', (string) file_get_contents($viewPath));
        self::assertStringContainsString($apiPrefix, (string) file_get_contents($modulePath));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function accountFeatureProvider(): iterable
    {
        yield 'merchants' => ['merchants', '/api/admin/merchant/', 'merchant-editor'];
        yield 'plans' => ['plans', '/api/admin/packvip/', 'plan-editor'];
    }

    #[DataProvider('transactionFeatureProvider')]
    public function testTransactionFeatureKeepsApiContract(string $id, string $api): void
    {
        $view = self::ADMIN . '/views/' . $id . '.html';
        $module = self::ADMIN . '/assets/features/' . $id . '.js';
        self::assertFileExists($view);
        self::assertFileExists($module);
        self::assertStringContainsString($api, (string) file_get_contents($module));
    }

    /** @return iterable<string, array{string, string}> */
    public static function transactionFeatureProvider(): iterable
    {
        yield 'orders' => ['orders', '/api/admin/order/'];
        yield 'callbill' => ['callbill', '/api/admin/callbill/'];
        yield 'alerts' => ['alerts', '/api/admin/alert/'];
    }

    public function testVersionAndUiModulesKeepTheirRuntimeContract(): void
    {
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

globalThis.window = { location: { origin: 'https://admin.example.test' } };
const version = await import(pathToFileURL(process.argv[1]).href);
if (version.assetUrl('/admin/assets/app.js') !== 'https://admin.example.test/admin/assets/app.js?v=admin-modules-v7') {
    throw new Error('资源版本 URL 不符合约定');
}

const ui = await import(pathToFileURL(process.argv[2]).href);
if (ui.escapeHtml(`<a data-x="'">&</a>`) !== '&lt;a data-x=&quot;&#39;&quot;&gt;&amp;&lt;/a&gt;') {
    throw new Error('HTML 转义语义发生变化');
}
JS;

        [$exitCode, $output] = $this->runNode($script, [
            self::ADMIN . '/assets/version.js',
            self::ADMIN . '/assets/ui.js',
        ]);

        self::assertSame(0, $exitCode, $output);
    }

    public function testApiModuleAddsAuthorizationAndHandlesExpiredSession(): void
    {
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const storage = new Map([['cx_admin_token', 'secret-token']]);
globalThis.localStorage = {
    getItem: (key) => storage.get(key) ?? null,
    removeItem: (key) => storage.delete(key),
};
let redirectedTo = null;
globalThis.window = { location: { assign: (url) => { redirectedTo = url; } } };
let requestOptions = null;
globalThis.fetch = async (_url, options) => {
    requestOptions = options;
    return { status: 200 };
};

const { adminFetch } = await import(pathToFileURL(process.argv[1]).href);
await adminFetch('/api/admin/dashboard');
if (requestOptions.headers.get('Authorization') !== 'Bearer secret-token') {
    throw new Error('管理员鉴权头未注入');
}

globalThis.fetch = async () => ({ status: 401 });
let errorMessage = null;
try {
    await adminFetch('/api/admin/dashboard');
} catch (error) {
    errorMessage = error.message;
}
if (storage.has('cx_admin_token') || redirectedTo !== '/admin_login.html' || errorMessage !== '管理员登录状态已失效') {
    throw new Error('401 会话失效处理不符合约定');
}
JS;

        [$exitCode, $output] = $this->runNode($script, [self::ADMIN . '/assets/api.js']);

        self::assertSame(0, $exitCode, $output);
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function coreModuleProvider(): iterable
    {
        yield 'version' => ['version.js', ['const ASSET_VERSION', 'function assetUrl']];
        yield 'api' => ['api.js', ['async function adminFetch']];
        yield 'ui' => ['ui.js', [
            'function escapeHtml',
            'function safeCreateIcons',
            'function showToast',
            'function showConfirm',
        ]];
    }

    /** @return iterable<string, array{string}> */
    public static function firstFeatureProvider(): iterable
    {
        foreach (self::firstFeatureIds() as $id) {
            yield $id => [$id];
        }
    }

    /** @return list<string> */
    private static function firstFeatureIds(): array
    {
        return ['dashboard', 'system-update', 'cloud-monitor'];
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
