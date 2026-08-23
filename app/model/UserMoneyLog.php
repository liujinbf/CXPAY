<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

class UserMoneyLog extends Model
{
    protected $table = 'cx_user_money_log';
    public $timestamps = false;
    protected $guarded = [];

    /**
     * 写入商户余额变动明细记录
     */
    public static function log(int $merchantId, string $money, string $before, string $after, string $memo = ''): self
    {
        return self::create([
            'merchant_id' => $merchantId,
            'money'       => $money,
            'before'      => $before,
            'after'       => $after,
            'memo'        => $memo,
            'create_time' => time(),
        ]);
    }
}
