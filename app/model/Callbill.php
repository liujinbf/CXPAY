<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 挂机账单数据模型
 */
class Callbill extends Model
{
    protected $table = 'cx_callbill';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $guarded = [];
}
