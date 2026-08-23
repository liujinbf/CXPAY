<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 支付通道数据模型
 */
class Channel extends Model
{
    protected $table = 'cx_pay_channel';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $guarded = [];
}
