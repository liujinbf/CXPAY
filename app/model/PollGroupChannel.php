<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 轮询组通道关联明细模型
 *
 * @property int $id
 * @property int $group_id 轮询组ID
 * @property int $channel_id 支付通道ID
 * @property int $weight 调度权重 (1-100)
 * @property int $create_time
 */
class PollGroupChannel extends Model
{
    protected $table = 'cx_poll_group_channel';
    public $timestamps = false;

    protected $fillable = [
        'group_id',
        'channel_id',
        'weight',
        'create_time',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(PollGroup::class, 'group_id', 'id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id', 'id');
    }
}
