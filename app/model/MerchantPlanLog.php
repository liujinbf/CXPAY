<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 商户套餐购买日志模型
 */
class MerchantPlanLog extends Model
{
    protected $table = 'cx_merchant_plan_log';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $guarded = [];
}
