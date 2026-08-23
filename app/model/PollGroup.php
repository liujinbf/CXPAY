<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 轮询组模型
 *
 * @property int    $id
 * @property string $name 轮询组名称
 * @property string $c_type 对应支付分类（wxpay/alipay/qqpay/usdt）
 * @property int    $strategy 调度策略 (1: 加权随机 Weighted Random, 2: 顺序轮询 Round-Robin, 3: 最小负载优先 Least-Money)
 * @property int    $merchant_id 0为平台全局，>0为商户专属
 * @property int    $status 1正常 0禁用
 * @property int    $create_time
 * @property int    $update_time
 */
class PollGroup extends Model
{
    public const STRATEGY_WEIGHTED_RANDOM = 1; // 加权随机
    public const STRATEGY_ROUND_ROBIN     = 2; // 顺序平滑轮询
    public const STRATEGY_LEAST_MONEY     = 3; // 今日累计收款最少优先

    protected $table = 'cx_poll_group';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'c_type',
        'strategy',
        'merchant_id',
        'status',
        'create_time',
        'update_time',
    ];

    /**
     * 关联的轮询组通道明细 (带权重)
     */
    public function groupChannels(): HasMany
    {
        return $this->hasMany(PollGroupChannel::class, 'group_id', 'id');
    }

    /**
     * 关联的具体通道实体
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(
            Channel::class,
            'cx_poll_group_channel',
            'group_id',
            'channel_id'
        )->withPivot('weight', 'create_time');
    }
}
