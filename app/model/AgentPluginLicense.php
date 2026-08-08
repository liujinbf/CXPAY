<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 代理端插件购买授权记录模型 (cx_agent_plugin_license)
 *
 * 记录每个代理站点（domain）已购买的支付插件及其授权有效期。
 * - expire_time = -1 表示永久授权
 * - expire_time = 0  表示尚未购买（不应存在此记录，用于防御性编码）
 * - expire_time > 0  表示时间戳到期
 */
class AgentPluginLicense extends Model
{
    protected $table = 'cx_agent_plugin_license';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'domain',          // 代理站点域名，如 pay.example.com
        'plugin_id',       // 插件标识，如 cxpay.wxpay.clerk
        'pkg_type',        // 购买套期：month / quarter / year / forever
        'amount',          // 实付价格（元）
        'expire_time',     // 授权到期时间戳；-1 = 永久
        'create_time',     // 购买时间戳
    ];

    /**
     * 判断当前授权记录是否有效
     */
    public function isValid(): bool
    {
        $expire = (int)$this->expire_time;
        return $expire === -1 || $expire > time();
    }

    /**
     * 计算到期剩余天数；永久授权返回 PHP_INT_MAX；已过期返回 0。
     */
    public function daysLeft(): int
    {
        $expire = (int)$this->expire_time;
        if ($expire === -1) {
            return PHP_INT_MAX;
        }
        $diff = $expire - time();
        return $diff > 0 ? (int)ceil($diff / 86400) : 0;
    }

    /**
     * 格式化到期时间供前端展示
     */
    public function expireLabel(): string
    {
        $expire = (int)$this->expire_time;
        if ($expire === -1) {
            return '永久授权';
        }
        if ($expire > time()) {
            return date('Y-m-d', $expire) . '（剩余 ' . $this->daysLeft() . ' 天）';
        }
        return '已到期';
    }
}
