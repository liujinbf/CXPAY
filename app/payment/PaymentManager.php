<?php

declare(strict_types=1);

namespace app\payment;

use app\payment\Contracts\PaymentDriverInterface;
use app\payment\Contracts\MonitorableDriverInterface;
use app\payment\Contracts\AccountCapabilityDetectorInterface;
use app\payment\Contracts\AccountAuthorizationInterface;
use app\payment\Plugin\PluginManager;
use InvalidArgumentException;

/**
 * 支付驱动加载与生命周期管理器
 */
class PaymentManager
{
    /** @var array<string, string> c_type => 驱动类全名 */
    protected static array $drivers = [];

    /** @var array<string, PaymentDriverInterface> 进程内单例缓存 */
    protected static array $instances = [];

    /** @var array<string, string> c_type => 插件 ID */
    protected static array $driverPlugins = [];

    /**
     * 注册支付驱动类
     *
     * @param string $cType 通道类型标识（如 alipay_app_asst）
     * @param string $class 实现了 PaymentDriverInterface 的驱动类
     */
    public static function register(string $cType, string $class): void
    {
        RemovedPaymentDrivers::assertAllowed($cType);

        if (!is_subclass_of($class, PaymentDriverInterface::class)) {
            throw new InvalidArgumentException("驱动类 {$class} 必须实现 PaymentDriverInterface");
        }
        static::$drivers[$cType] = $class;
    }

    public static function registerPluginDriver(string $cType, string $class, string $pluginId): void
    {
        RemovedPaymentDrivers::assertAllowed($cType);

        if (!is_subclass_of($class, PaymentDriverInterface::class)) {
            throw new InvalidArgumentException("插件驱动类 {$class} 必须实现 PaymentDriverInterface");
        }
        if (isset(static::$drivers[$cType])
            && (static::$driverPlugins[$cType] ?? '') !== $pluginId) {
            throw new InvalidArgumentException("插件驱动标识与现有驱动冲突: {$cType}");
        }
        static::$drivers[$cType] = $class;
        static::$driverPlugins[$cType] = $pluginId;
    }

    /**
     * 获取驱动实例。
     * 所有驱动必须通过 PluginManager 安装并启用后才能使用，
     * 源码包不内置任何支付通道驱动实现。
     */
    public static function make(string $cType): PaymentDriverInterface
    {
        RemovedPaymentDrivers::assertAllowed($cType);
        static::discoverDrivers();
        if (!static::driverIsEnabled($cType)) {
            throw new InvalidArgumentException("支付通道插件已停用: {$cType}");
        }
        if (isset(static::$instances[$cType])) {
            return static::$instances[$cType];
        }

        if (!isset(static::$drivers[$cType])) {
            throw new InvalidArgumentException(
                "支付通道驱动尚未安装或尚未启用: {$cType}。请在插件商城下载并安装对应插件包。"
            );
        }

        $class = static::$drivers[$cType];
        if (!static::classIsAvailable($class)) {
            unset(static::$drivers[$cType], static::$instances[$cType]);
            throw new InvalidArgumentException("支付通道驱动已损坏或已停用: {$cType}");
        }
        return static::$instances[$cType] = new $class();
    }

    /**
     * 判断驱动是否已通过插件安装注册。
     * 源码包中无内置驱动，必须通过 PluginManager 安装并启用。
     */
    public static function has(string $cType): bool
    {
        if (RemovedPaymentDrivers::contains($cType)) {
            return false;
        }

        static::discoverDrivers();
        if (!static::driverIsEnabled($cType)) {
            return false;
        }

        return isset(static::$drivers[$cType])
            && static::classIsAvailable(static::$drivers[$cType]);
    }

    /**
     * 获取通道明确声明的监控方式；未声明的普通驱动按无需监控处理。
     */
    public static function monitorMode(string $cType): string
    {
        $driver = static::make($cType);
        if (!$driver instanceof MonitorableDriverInterface) {
            return MonitorableDriverInterface::MODE_NONE;
        }

        $mode = $driver->monitorMode();
        $allowed = [
            MonitorableDriverInterface::MODE_NONE,
            MonitorableDriverInterface::MODE_PUSH,
            MonitorableDriverInterface::MODE_CALLBACK,
            MonitorableDriverInterface::MODE_SERVER,
        ];
        if (!in_array($mode, $allowed, true)) {
            throw new InvalidArgumentException("支付通道驱动返回了非法监控方式: {$cType}");
        }
        return $mode;
    }

    /** 挂机助手推送类通道必须依靠心跳决定在线状态。 */
    public static function requiresHeartbeat(string $cType): bool
    {
        return static::monitorMode($cType) === MonitorableDriverInterface::MODE_PUSH;
    }

    /** @return array{status:string,message:string,capabilities?:array<string,bool>} */
    public static function detectAccountCapabilities(string $cType, array $config): array
    {
        $driver = static::make($cType);
        if (!$driver instanceof AccountCapabilityDetectorInterface) {
            return [
                'status' => AccountCapabilityDetectorInterface::STATUS_UNKNOWN,
                'message' => '当前支付插件不支持账号能力探测',
            ];
        }
        $result = $driver->detectAccountCapabilities($config);
        $allowed = [
            AccountCapabilityDetectorInterface::STATUS_UNKNOWN,
            AccountCapabilityDetectorInterface::STATUS_RECEIPT_AVAILABLE,
            AccountCapabilityDetectorInterface::STATUS_RECEIPT_NOT_OPENED,
            AccountCapabilityDetectorInterface::STATUS_BOOK_AVAILABLE,
            AccountCapabilityDetectorInterface::STATUS_REAUTH_REQUIRED,
            AccountCapabilityDetectorInterface::STATUS_TEMPORARY_ERROR,
        ];
        if (!in_array($result['status'] ?? '', $allowed, true)) {
            throw new InvalidArgumentException("支付插件返回了非法账号能力状态: {$cType}");
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public static function startAccountAuthorization(string $cType, array $config): array
    {
        $driver = static::make($cType);
        if (!$driver instanceof AccountAuthorizationInterface) {
            throw new InvalidArgumentException('当前支付插件不支持扫码授权');
        }
        return $driver->startAccountAuthorization($config);
    }

    /** @return array<string, mixed> */
    public static function pollAccountAuthorization(string $cType, string $sessionId, array $config): array
    {
        $driver = static::make($cType);
        if (!$driver instanceof AccountAuthorizationInterface) {
            throw new InvalidArgumentException('当前支付插件不支持扫码授权');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $sessionId)) {
            throw new InvalidArgumentException('授权会话 ID 不合法');
        }
        return $driver->pollAccountAuthorization($sessionId, $config);
    }

    /**
     * 获取单个驱动的完整元数据配置
     */
    public static function getMeta(string $cType): array
    {
        $driver = static::make($cType);
        $meta = $driver->getMeta();
        if ($driver instanceof MonitorableDriverInterface) {
            $meta['monitor_mode'] = static::monitorMode($cType);
        }
        return $meta;
    }

    /**
     * 获取所有已发现/已注册驱动元数据列表
     *
     * 修复：原来用 $class::getMeta() 静态调用实例方法，PHP 8 严格模式下会产生 Deprecation
     *        改为先 new $class() 实例化再调用实例方法
     */
    public static function getRegisteredDrivers(): array
    {
        static::discoverDrivers();
        $list = [];
        foreach (static::$drivers as $cType => $class) {
            if (RemovedPaymentDrivers::contains($cType)
                || !static::driverIsEnabled($cType)
                || !static::classIsAvailable($class)) {
                continue;
            }
            try {
                // 使用单例缓存（如果已实例化则复用）
                $instance     = static::$instances[$cType] ?? new $class();
                $meta = $instance->getMeta();
                if (($meta['internal'] ?? false) === true) {
                    continue;
                }
                if ($instance instanceof MonitorableDriverInterface) {
                    $meta['monitor_mode'] = static::monitorMode($cType);
                }
                $list[$cType] = $meta;
            } catch (\Throwable $e) {
                // 驱动报错不影响其他驱动列表
                $list[$cType] = ['name' => $cType, 'title' => $cType . ' (load error)'];
            }
        }
        return $list;
    }

    /**
     * 加载已通过插件市场安装并启用的驱动。
     * 源码包不内置任何驱动实现，全部驱动来自安装的加密插件包。
     */
    protected static function discoverDrivers(): void
    {
        PluginManager::discoverEnabledDrivers();
    }

    public static function flush(): void
    {
        static::$drivers = [];
        static::$instances = [];
        static::$driverPlugins = [];
    }

    public static function pluginId(string $cType): ?string
    {
        static::discoverDrivers();
        return static::$driverPlugins[$cType] ?? null;
    }

    private static function driverIsEnabled(string $cType): bool
    {
        $pluginId = static::$driverPlugins[$cType] ?? null;
        if ($pluginId === null) {
            return true;
        }
        $entry = PluginManager::registry()->get($pluginId);
        if ($entry === null) {
            return true;
        }
        return ($entry['enabled'] ?? true) === true;
    }

    private static function classIsAvailable(string $class): bool
    {
        if (!class_exists($class) || !is_subclass_of($class, PaymentDriverInterface::class)) {
            return false;
        }
        try {
            $meta = (new $class())->getMeta();
            return ($meta['available'] ?? true) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
