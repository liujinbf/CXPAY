<?php

namespace app\common\library\payment;

use app\common\library\Authcode;
use think\facade\Cache;
use think\facade\Config;

/**
 * 支付通道驱动管理器
 *
 * 取代旧 PayplugClass 的 scandir + ReflectionClass 反射机制：
 *   - 自动发现 plugins/{c_type}/（含 plugin.json）→ 约定驱动类 plugins\{c_type}\Driver
 *   - 再合并 config/payment.php 的 drivers 显式注册（外部/商城插件，显式优先）
 *   - 实例缓存，避免重复构造
 *   - config() 元数据用 Redis 缓存
 *   - 提供旧库加密配置的解密入口（Authcode 兼容层）
 */
class PaymentManager
{
    /** @var array<string, PaymentChannelInterface> 进程内实例缓存 */
    protected static array $instances = [];

    /** @var array<string,string>|null c_type => 驱动类，进程内注册表缓存 */
    protected static ?array $registry = null;

    /**
     * 通道注册表：自动发现 plugins/ + 合并 config 显式注册。
     * @return array<string,string> c_type => 驱动类全名
     */
    public static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }

        $map = [];
        // 1) 自动发现 plugins/*/plugin.json → 约定类 ...\plugins\{c_type}\Driver
        $dir = __DIR__ . '/plugins';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*/plugin.json') ?: [] as $manifest) {
                $cType = basename(dirname($manifest));
                $map[$cType] = __NAMESPACE__ . '\\plugins\\' . $cType . '\\Driver';
            }
        }
        // 2) 合并显式注册（外部/商城插件，显式覆盖约定）
        $explicit = Config::get('payment.drivers', []);
        if (is_array($explicit)) {
            $map = array_merge($map, $explicit);
        }

        return self::$registry = $map;
    }

    /** 清空进程内注册表/实例缓存（测试或热加载新插件后调用） */
    public static function flush(): void
    {
        self::$registry = null;
        self::$instances = [];
    }

    /**
     * 按 c_type 取驱动实例
     *
     * @throws \InvalidArgumentException 未注册的通道
     */
    public static function make(string $cType): PaymentChannelInterface
    {
        if (isset(self::$instances[$cType])) {
            return self::$instances[$cType];
        }

        $registry = self::registry();
        if (!isset($registry[$cType])) {
            throw new \InvalidArgumentException("未注册的支付通道驱动: {$cType}");
        }

        $class = $registry[$cType];
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("支付通道驱动类不存在: {$class}");
        }

        $instance = new $class();
        if (!$instance instanceof PaymentChannelInterface) {
            throw new \InvalidArgumentException("驱动 {$class} 必须实现 PaymentChannelInterface");
        }

        return self::$instances[$cType] = $instance;
    }

    /**
     * 是否已注册该通道
     */
    public static function has(string $cType): bool
    {
        return isset(self::registry()[$cType]);
    }

    /**
     * 已注册的全部 c_type
     * @return string[]
     */
    public static function registered(): array
    {
        return array_keys(self::registry());
    }

    /**
     * 取通道 config() 元数据（Redis 缓存）
     */
    public static function metadata(string $cType): array
    {
        $ttl = (int) Config::get('payment.meta_cache_ttl', 3600);
        $key = "payment:meta:{$cType}";

        return Cache::remember($key, function () use ($cType) {
            return self::make($cType)->config();
        }, $ttl);
    }

    /**
     * 解密旧库通道配置（迁移兼容）
     *
     * @param string|array $config    ba_channel.config（JSON 字符串或已解析数组）
     * @param int          $operation 1=解密失败保留原值，0=失败置 false
     * @return array 明文配置
     */
    public static function decryptChannelConfig($config, int $operation = 1): array
    {
        if (is_string($config)) {
            $config = json_decode($config, true) ?: [];
        }
        if (!$config) {
            return [];
        }

        return Authcode::legacy()->decryptArray($config, $operation);
    }
}
