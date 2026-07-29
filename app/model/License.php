<?php

declare(strict_types=1);

namespace app\model;

use Illuminate\Database\Eloquent\Model;

/**
 * 授权站点与模块订阅模型 (cx_license)
 */
class License extends Model
{
    protected $table = 'cx_license';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'domain',
        'auth_key',
        'agent_id',
        'modules', // JSON 保存已订阅模块与到期时间 ['wx_cloud' => 1735689600, 'alipay_scan' => 1735689600]
        'status',  // 1: 授权有效, 0: 封禁冻结
        'create_time',
        'update_time',
    ];
}
