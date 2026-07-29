<?php

namespace app\common\library\payment;

use app\common\model\ThemeSetting;

/**
 * 收银台支付模板（主题）注册表
 *
 * 模板清单自动加载自 app/common/library/theme/cashier_templates/<key>.php（return 配色数组）。
 * 新增模板：往该目录丢一个 <key>.php，后台「支付模板」页自动出现，无需改本类。
 *
 * 平台侧（后台「主题设置 → 支付模板」）：
 *   cashier.default   平台默认模板 key（商户未选/所选被停用时兜底）
 *   cashier.enabled   启用模板 key 列表（JSON；空=全部启用）
 *
 * 每商户 ba_user.paypage 存自选模板 key；收银台 gateway/Submit::renderCashier 按 key 取配色渲染。
 */
class CashierTemplate
{
    public const DEFAULT = 'default';

    /** 扫描目录为空时的内置回退（保证收银台永不白屏） */
    protected const BUILTIN = [
        'default' => [
            'name' => '经典蓝', 'desc' => '浅灰背景 + 蓝色主色，通用稳重',
            'bg' => '#f5f6fa', 'card' => '#ffffff', 'primary' => '#1a73e8',
            'text' => '#333333', 'sub' => '#999999', 'border' => '#eeeeee',
        ],
        'minimal' => [
            'name' => '简约白', 'desc' => '纯白极简 + 绿色主色，清爽',
            'bg' => '#ffffff', 'card' => '#ffffff', 'primary' => '#10b981',
            'text' => '#222222', 'sub' => '#9ca3af', 'border' => '#f0f0f0',
        ],
        'dark' => [
            'name' => '暗黑', 'desc' => '深色背景 + 青色主色，护眼',
            'bg' => '#0f172a', 'card' => '#1e293b', 'primary' => '#38bdf8',
            'text' => '#e2e8f0', 'sub' => '#94a3b8', 'border' => '#334155',
        ],
    ];

    /** @var array<string,array>|null 运行时缓存 */
    protected static ?array $cache = null;

    /** 全部模板（key => 配色数组）；自动扫描清单目录，为空则回退内置 */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];
        $dir = __DIR__ . '/../theme/cashier_templates';
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $def = include $file;
            if (is_array($def) && isset($def['bg'], $def['primary'])) {
                $out[$key] = $def;
            }
        }
        self::$cache = $out ?: self::BUILTIN;
        return self::$cache;
    }

    /** 合法模板 key 列表 */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** 取某模板配色（非法/停用 key 回退平台默认） */
    public static function theme(string $key): array
    {
        $all = self::all();
        if (isset($all[$key])) {
            return $all[$key];
        }
        return $all[self::defaultKey()] ?? reset($all);
    }

    /** 供前端展示的模板列表（key/name/desc + 预览色） */
    public static function list(): array
    {
        $out = [];
        foreach (self::all() as $key => $t) {
            $out[] = [
                'key'     => $key,
                'name'    => $t['name'] ?? $key,
                'desc'    => $t['desc'] ?? '',
                'bg'      => $t['bg'],
                'card'    => $t['card'],
                'primary' => $t['primary'],
                'text'    => $t['text'],
                'sub'     => $t['sub'],
            ];
        }
        return $out;
    }

    /** 启用的模板 key 列表（cashier.enabled 为空视为全部启用） */
    public static function enabledKeys(): array
    {
        $raw = ThemeSetting::getVal('cashier.enabled', '');
        $keys = $raw ? (json_decode($raw, true) ?: []) : [];
        $valid = self::keys();
        $keys = array_values(array_intersect($keys, $valid));
        return $keys ?: $valid; // 空/全非法 → 全部启用
    }

    /** 平台默认模板 key（cashier.default；非法回退 default/首个） */
    public static function defaultKey(): string
    {
        $key  = (string) ThemeSetting::getVal('cashier.default', self::DEFAULT);
        $keys = self::keys();
        if (in_array($key, $keys, true)) {
            return $key;
        }
        return in_array(self::DEFAULT, $keys, true) ? self::DEFAULT : (string) ($keys[0] ?? self::DEFAULT);
    }

    /** 仅启用模板的展示列表（供商户中心自选） */
    public static function enabledList(): array
    {
        $enabled = self::enabledKeys();
        return array_values(array_filter(self::list(), fn ($t) => in_array($t['key'], $enabled, true)));
    }

    /**
     * 解析商户实际生效的收银台模板 key：
     * 商户所选在启用集内则用之，否则回退平台默认。
     */
    public static function resolveForUser(?string $userPaypage): string
    {
        $enabled = self::enabledKeys();
        if ($userPaypage && in_array($userPaypage, $enabled, true)) {
            return $userPaypage;
        }
        return self::defaultKey();
    }
}
