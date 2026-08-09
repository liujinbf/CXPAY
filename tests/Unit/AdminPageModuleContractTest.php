<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminPageModuleContractTest extends TestCase
{
    private const ADMIN = __DIR__ . '/../../public/admin';

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

    public function testAdminPageLoadsCoreModulesBeforeCompatibilityWrappers(): void
    {
        $html = file_get_contents(self::ADMIN . '/index.html');
        self::assertIsString($html);
        self::assertStringContainsString("import * as api from '/admin/assets/api.js';", $html);
        self::assertStringContainsString("import * as ui from '/admin/assets/ui.js';", $html);
        self::assertStringContainsString('window.CXAdmin = Object.freeze({ api, ui });', $html);
        self::assertStringContainsString('return window.CXAdmin.api.adminFetch(...args);', $html);
        self::assertStringContainsString('return window.CXAdmin.ui.escapeHtml(value);', $html);
        self::assertStringContainsString('return window.CXAdmin.ui.showConfirm(title, message, isDanger);', $html);
    }

    public function testVersionAndUiModulesKeepTheirRuntimeContract(): void
    {
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

globalThis.window = { location: { origin: 'https://admin.example.test' } };
const version = await import(pathToFileURL(process.argv[1]).href);
if (version.assetUrl('/admin/assets/app.js') !== 'https://admin.example.test/admin/assets/app.js?v=admin-modules-v1') {
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
