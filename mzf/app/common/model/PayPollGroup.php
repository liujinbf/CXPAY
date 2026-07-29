<?php

namespace app\common\model;

use think\Model;

/**
 * 轮询规则组模型（ba_pay_pollgroup）
 * 商户按支付方式建规则(random/weight/priority)，下单时在规则内通道中按模式选通道。
 */
class PayPollGroup extends Model
{
    protected $name = 'pay_pollgroup';

    protected $autoWriteTimestamp = true;
}
