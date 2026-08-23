<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 交易订单模型
 */
class Order extends Model
{
    protected $table = 'cx_order';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $guarded = [];
}
