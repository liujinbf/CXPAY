<?php

namespace app\admin\model;

use think\Model;
use think\model\relation\BelongsTo;

/**
 * User 模型
 * @property int    $id      用户ID
 * @property string password 密码密文
 */
class User extends Model
{
    protected $autoWriteTimestamp = true;

    /**
     * 新增用户前自动生成对接 PID（随机 10 位数字，唯一）
     */
    protected static function onBeforeInsert($model): void
    {
        if (empty($model->pid)) {
            $model->pid = self::generatePid();
        }
    }

    /**
     * 生成唯一的商户号（M + 10 位随机数字）
     */
    public static function generatePid(): string
    {
        do {
            $pid = 'M' . random_int(1000000000, 9999999999);
        } while (self::where('pid', $pid)->find());
        return $pid;
    }

    public function getAvatarAttr($value): string
    {
        return full_url($value, false, config('buildadmin.default_avatar'));
    }

    public function setAvatarAttr($value): string
    {
        return $value == full_url('', false, config('buildadmin.default_avatar')) ? '' : $value;
    }

    public function getMoneyAttr($value): string
    {
        return bcdiv($value, 100, 2);
    }

    public function setMoneyAttr($value): string
    {
        return bcmul($value, 100, 2);
    }

    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class, 'group_id');
    }

    /**
     * 重置用户密码
     * @param int|string $uid         用户ID
     * @param string     $newPassword 新密码
     * @return int|User
     */
    public function resetPassword(int|string $uid, string $newPassword): int|User
    {
        return $this->where(['id' => $uid])->update(['password' => hash_password($newPassword), 'salt' => '']);
    }
}