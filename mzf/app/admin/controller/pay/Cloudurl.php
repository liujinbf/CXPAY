<?php

namespace app\admin\controller\pay;

use app\common\controller\Backend;
use app\common\model\PayCloudurl as CloudurlModel;

/**
 * 微信云端URL管理
 */
class Cloudurl extends Backend
{
    protected object $model;

    protected string|array $quickSearchField = ['name', 'url'];

    protected string|array $preExcludeFields = ['id', 'create_time', 'update_time'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new CloudurlModel();
    }
}
