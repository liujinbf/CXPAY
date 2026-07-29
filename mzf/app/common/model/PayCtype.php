<?php

namespace app\common\model;

use think\Model;

/**
 * 通道类型模型（ba_pay_ctype）—— 供商户中心/网关按 c_type 查询通道类型目录
 */
class PayCtype extends Model
{
    protected $name = 'pay_ctype';

    protected $autoWriteTimestamp = true;
}
