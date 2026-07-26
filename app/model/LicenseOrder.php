<?php

declare(strict_types=1);

namespace app\model;

use support\Model;

/**
 * 授权中心模块订阅订单模型 (cx_license_order)
 */
class LicenseOrder extends Model
{
    protected $table = 'cx_license_order';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'trade_no',
        'domain',
        'module_key',  // 如 wx_cloud, ipad_cloud, pc_afk
        'pkg_type',    // month, quarter, year, forever
        'amount',      // 订阅价格
        'pay_type',    // alipay, wxpay
        'status',      // 0: 待支付, 1: 成功生效
        'create_time',
        'pay_time',
    ];
}
