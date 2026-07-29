<?php

namespace app\common\model;

use think\Model;

/**
 * 订单模型（ba_pay_order），主键字符串 trade_no
 */
class PayOrder extends Model
{
    protected $name = 'pay_order';

    protected $pk = 'trade_no';

    protected $autoWriteTimestamp = true;
}
