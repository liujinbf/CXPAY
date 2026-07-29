<?php

namespace app\common\model;

use think\Model;

/**
 * 会员套餐模型（ba_pay_packvip）
 */
class PayPackvip extends Model
{
    protected $name = 'pay_packvip';

    protected $autoWriteTimestamp = true;

    // bind_ctype 存 JSON，读出为数组
    public function getBindCtypeAttr($value): array
    {
        if (is_array($value)) return $value;
        return $value ? (json_decode($value, true) ?: []) : [];
    }

    public function setBindCtypeAttr($value): string
    {
        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
    }
}
