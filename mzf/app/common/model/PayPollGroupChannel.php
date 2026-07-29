<?php

namespace app\common\model;

use think\Model;

/**
 * 轮询规则-通道关联模型（ba_pay_pollgroup_channel）
 * group_id ↔ channel_id 多对多，weight 为该通道在此规则内的权重。
 */
class PayPollGroupChannel extends Model
{
    protected $name = 'pay_pollgroup_channel';

    protected $autoWriteTimestamp = false;
}
