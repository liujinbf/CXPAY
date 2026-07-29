<?php

namespace app\admin\model\notify;

use think\Model;

/**
 * 通知模板
 */
class Template extends Model
{
    protected $name = 'notify_template';

    protected $autoWriteTimestamp = true;

    protected static function onAfterInsert($model): void
    {
        if (is_null($model->weigh)) {
            $pk = $model->getPk();
            $model->where($pk, $model[$pk])->update(['weigh' => $model[$pk]]);
        }
    }
}
