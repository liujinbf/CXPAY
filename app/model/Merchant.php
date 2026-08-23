<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 商户数据模型
 */
class Merchant extends Model
{
    protected $table = 'cx_merchant';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $guarded = [];
}
