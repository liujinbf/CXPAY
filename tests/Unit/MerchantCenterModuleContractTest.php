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
if (version.assetUrl('/merchant/assets/app.js') !== 'https://merchant.example.test/merchant/assets/app.js?v=merchant-modules-v5') {
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

    public function testChannelModulesExistAndKeepApiContracts(): void
    {
        $paths = [
            self::MERCHANT . '/views/channels.html',
            self::MERCHANT . '/assets/features/channels.js',
            self::MERCHANT . '/assets/features/channel-editor.js',
            self::MERCHANT . '/assets/features/channel-authorization.js',
        ];
        $combined = '';
        foreach ($paths as $path) {
            self::assertFileExists($path);
            $source = (string) file_get_contents($path);
            self::assertLessThanOrEqual(400, substr_count($source, "\n") + 1, $path);
            $combined .= $source;
        }
        self::assertStringContainsString('data-feature="channels"', (string) file_get_contents($paths[0]));
        foreach ([
            '/api/merchant/channel/list',
            '/api/merchant/channel/save',
            '/api/merchant/channel/toggle',
            '/api/merchant/channel/delete',
            '/api/merchant/channel/capabilities',
            '/api/merchant/channel/authorization/start',
            '/api/merchant/channel/authorization/poll',
            '/api/merchant/channel/drivers',
            '/api/merchant/bill-source/status',
            '/api/merchant/bill-source/rotate-token',
        ] as $api) {
            self::assertStringContainsString($api, $combined);
        }
    }

    public function testChannelAuthorizationStopsPollingWhenParentSignalAborts(): void
    {
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const parent = new AbortController();
let pollCount = 0;
const api = {
    merchantFetch: async (url) => {
        if (url.endsWith('/start')) {
            return { json: async () => ({ code: 1, data: { session_id: 'session-1' } }) };
        }
        if (url.endsWith('/poll')) {
            pollCount += 1;
            parent.abort();
            return { json: async () => ({ code: 1, data: { status: 'WAITING' } }) };
        }
        throw new Error(`意外请求 ${url}`);
    },
};
const ui = { showToast: () => {}, showConfirm: async () => true };
const { createChannelAuthorization } = await import(pathToFileURL(process.argv[1]).href);
const controller = createChannelAuthorization({ root: {}, api, ui, signal: parent.signal });
await controller.start(7, '微信');
await new Promise((resolve) => setTimeout(resolve, 20));
if (pollCount !== 1) throw new Error(`中止后仍继续轮询：${pollCount}`);
controller.dispose();
JS;

        [$exitCode, $output] = $this->runNode($script, [
            self::MERCHANT . '/assets/features/channel-authorization.js',
        ]);

        self::assertSame(0, $exitCode, $output);
    }

    public function testCashierAndPollGroupFeaturesKeepContracts(): void
    {
        foreach (['cashier', 'poll-groups'] as $name) {
            $view = self::MERCHANT . '/views/' . $name . '.html';
            $module = self::MERCHANT . '/assets/features/' . $name . '.js';
            self::assertFileExists($view);
            self::assertFileExists($module);
            self::assertLessThanOrEqual(400, substr_count((string) file_get_contents($view), "\n") + 1, $view);
            self::assertLessThanOrEqual(400, substr_count((string) file_get_contents($module), "\n") + 1, $module);
        }

        $cashier = (string) file_get_contents(self::MERCHANT . '/assets/features/cashier.js');
        $pollGroups = (string) file_get_contents(self::MERCHANT . '/assets/features/poll-groups.js');
        self::assertStringContainsString('cx_cashier_config', $cashier);
        self::assertStringContainsString('/api/merchant/channel/list', $pollGroups);
    }

    public function testCashierConfigDefaultsAndTimeoutValidation(): void
    {
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';

const { readCashierConfig, normalizeCashierConfig, validateCashierConfig } = await import(
    pathToFileURL(process.argv[1]).href
);
const defaults = readCashierConfig({ getItem: () => null });
const expected = {
    notice: '', timeout: 180, redirect: 'return_url', custom_url: '',
    tts_enabled: true, mapi_mode: 'qrcode', float_min: '0.00',
    float_max: '0.09', theme: 'classic_blue',
};
if (JSON.stringify(defaults) !== JSON.stringify(expected)) {
    throw new Error(`默认配置不兼容：${JSON.stringify(defaults)}`);
}
const normalized = normalizeCashierConfig({ ...expected, timeout: '180', tts_enabled: 1 });
if (normalized.timeout !== 180 || normalized.tts_enabled !== true) {
    throw new Error('配置类型归一化失败');
}
if (!validateCashierConfig({ ...expected, timeout: 59 })) throw new Error('59 秒未返回验证错误');
if (!validateCashierConfig({ ...expected, timeout: 301 })) throw new Error('301 秒未返回验证错误');
if (validateCashierConfig(expected) !== null) throw new Error('合法超时被错误拒绝');
JS;

        [$exitCode, $output] = $this->runNode($script, [
            self::MERCHANT . '/assets/features/cashier.js',
        ]);
        self::assertSame(0, $exitCode, $output);
    }

    public function testTransactionAndPlanFeaturesKeepApiContracts(): void
    {
        foreach (['orders', 'finance', 'plans'] as $name) {
            $view = self::MERCHANT . '/views/' . $name . '.html';
            $module = self::MERCHANT . '/assets/features/' . $name . '.js';
            self::assertFileExists($view);
            self::assertFileExists($module);
            self::assertLessThanOrEqual(400, substr_count((string) file_get_contents($view), "\n") + 1, $view);
            self::assertLessThanOrEqual(400, substr_count((string) file_get_contents($module), "\n") + 1, $module);
        }

        $orders = (string) file_get_contents(self::MERCHANT . '/assets/features/orders.js');
        $finance = (string) file_get_contents(self::MERCHANT . '/assets/features/finance.js');
        $plans = (string) file_get_contents(self::MERCHANT . '/assets/features/plans.js');
        self::assertStringContainsString('/api/merchant/order/list', $orders);
        self::assertStringContainsString('/api/merchant/finance_log', $finance);
        self::assertStringContainsString('/api/merchant/plan/list', $plans);
        self::assertStringContainsString('/api/merchant/plan/buy', $plans);
        self::assertStringContainsString('plan_id', $plans);
        self::assertStringContainsString('application/x-www-form-urlencoded', $plans);
    }

    public function testOrderStatusMappingStaysCompatible(): void
    {
        $script = <<<'JS'
import { pathToFileURL } from 'node:url';
const { getOrderStatus } = await import(pathToFileURL(process.argv[1]).href);
const expected = { 0: '待支付', 1: '已完成', 2: '已超时/关闭', 3: '已退款' };
for (const [status, label] of Object.entries(expected)) {
    if (getOrderStatus(Number(status)).label !== label) {
        throw new Error(`订单状态 ${status} 映射错误`);
    }
}
JS;
        [$exitCode, $output] = $this->runNode($script, [
            self::MERCHANT . '/assets/features/orders.js',
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
