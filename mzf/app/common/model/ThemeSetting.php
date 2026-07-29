<?php

namespace app\common\model;

use think\Model;
use think\facade\Db;

/**
 * 主题设置（ba_theme_setting）—— 键值存储
 *
 * 存放主题相关的激活/启用/默认态，如：
 *   home.active       主页模板 key
 *   cashier.default   默认收银台模板 key
 *   cashier.enabled   启用的收银台模板 key（JSON 数组）
 *
 * 值统一按字符串读写；JSON 值由调用方 encode/decode。
 */
class ThemeSetting extends Model
{
    protected $name = 'theme_setting';

    protected $autoWriteTimestamp = false;

    /** 读取一个设置值（不存在返回 $default） */
    public static function getVal(string $name, ?string $default = null): ?string
    {
        $row = self::where('name', $name)->find();
        return $row ? (string) $row->value : $default;
    }

    /** 写入一个设置值（存在则更新，否则插入） */
    public static function setVal(string $name, string $value): void
    {
        $exists = self::where('name', $name)->find();
        if ($exists) {
            $exists->value       = $value;
            $exists->update_time = time();
            $exists->save();
        } else {
            self::create(['name' => $name, 'value' => $value, 'update_time' => time()]);
        }
    }
}
