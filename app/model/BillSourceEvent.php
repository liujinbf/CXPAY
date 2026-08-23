<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/** 授权采集端写入、等待 PC 拉取的真实账单事件。 */
class BillSourceEvent extends Model
{
    protected $table = 'cx_bill_source_event';
    protected $primaryKey = 'id';
    public $timestamps = false;
    protected $guarded = [];
}
