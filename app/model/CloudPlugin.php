<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 云端插件商品模型 (cx_cloud_plugin)
 *
 * 官方发布的、可供代理端购买授权的支付通道插件商品目录。
 * 每条记录对应一个 .cxpay-plugin 插件包。
 */
class CloudPlugin extends Model
{
    protected $table = 'cx_cloud_plugin';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'plugin_id',       // 插件唯一标识，如 cxpay.wxpay.clerk
        'name',            // 插件名称，如"微信小账本收款插件"
        'description',     // 插件简介
        'payment_type',    // 支付类型：alipay / wxpay / qqpay
        'version',         // 当前最新发布版本
        'price_month',     // 月付价格（元），0 = 免费
        'price_quarter',   // 季付价格（元）
        'price_year',      // 年付价格（元）
        'price_forever',   // 永久买断价格（元），-1 = 不提供此选项
        'status',          // 1: 上架销售, 0: 下架
        'sort_order',      // 排序权重，越小越靠前
        'create_time',     // 创建时间戳
        'update_time',     // 最后修改时间戳
    ];

    /**
     * 判断插件是否免费
     */
    public function isFree(): bool
    {
        return (float)($this->price_month ?? 0) <= 0
            && (float)($this->price_quarter ?? 0) <= 0
            && (float)($this->price_year ?? 0) <= 0
            && (float)($this->price_forever ?? 0) <= 0;
    }

    /**
     * 根据套期类型获取价格
     * @param string $pkgType month | quarter | year | forever
     */
    public function priceFor(string $pkgType): float
    {
        return match ($pkgType) {
            'month'   => (float)($this->price_month   ?? 0),
            'quarter' => (float)($this->price_quarter ?? 0),
            'year'    => (float)($this->price_year    ?? 0),
            'forever' => (float)($this->price_forever ?? 0),
            default   => 0.0,
        };
    }
}
