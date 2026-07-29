<?php

namespace app\admin\controller\pay;

use app\common\controller\Backend;
use app\common\model\PayPackvip as PackvipModel;

/**
 * 会员套餐管理
 */
class Packvip extends Backend
{
    protected object $model;

    protected string|array $quickSearchField = ['name'];

    protected string|array $preExcludeFields = ['id', 'create_time', 'update_time'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new PackvipModel();
    }
}
