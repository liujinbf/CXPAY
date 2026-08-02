<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 套餐模型
 */
class Plan extends Model
{
    protected $table = 'cx_plan';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $guarded = [];
}
