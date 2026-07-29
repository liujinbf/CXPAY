<?php

namespace app\common\library\theme;

use app\common\model\ThemeSetting;

/**
 * 主页模板注册表
 *
 * 模板清单自动加载自 app/common/library/theme/home_templates/<key>.php（return 元信息数组）。
 * 前台组件对应 web/src/views/frontend/home/templates/<key>.vue（前台 glob 自动导入）。
 * 新增主页模板：往清单目录丢 <key>.php，再加同名 .vue 组件，后台「主页模板」自动出现。
 *
 * 后台「主题设置 → 主页模板」存激活 key：home.active。
 */
class HomeTemplate
{
    public const DEFAULT = 'default';

    /** @var array<string,array>|null */
    protected static ?array $cache = null;

    /** 全部主页模板（key => 元信息） */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];
        foreach (glob(__DIR__ . '/home_templates/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $def = include $file;
            if (is_array($def)) {
                $out[$key] = $def;
            }
        }
        // 兜底：清单为空也至少保留 default，避免后台空列表
        if (!$out) {
            $out['default'] = ['name' => '默认', 'desc' => '', 'thumb' => ''];
        }
        self::$cache = $out;
        return $out;
    }

    /** 合法 key 列表 */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** 供前端展示的模板列表 */
    public static function list(): array
    {
        $out = [];
        foreach (self::all() as $key => $t) {
            $out[] = [
                'key'   => $key,
                'name'  => $t['name'] ?? $key,
                'desc'  => $t['desc'] ?? '',
                'thumb' => $t['thumb'] ?? '',
            ];
        }
        return $out;
    }

    /** 当前激活的主页模板 key（非法/未设回退默认/首个） */
    public static function active(): string
    {
        $key  = (string) ThemeSetting::getVal('home.active', self::DEFAULT);
        $keys = self::keys();
        if (in_array($key, $keys, true)) {
            return $key;
        }
        return in_array(self::DEFAULT, $keys, true) ? self::DEFAULT : (string) ($keys[0] ?? self::DEFAULT);
    }
}
