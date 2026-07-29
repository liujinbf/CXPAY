<?php

namespace app\admin\controller\pay;

use app\common\controller\Backend;
use app\common\model\PayCloudproxy as CloudproxyModel;

/**
 * 微信云端代理管理
 */
class Cloudproxy extends Backend
{
    protected object $model;

    protected string|array $quickSearchField = ['name', 'proxy_ip'];

    protected string|array $preExcludeFields = ['id', 'create_time', 'update_time'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new CloudproxyModel();
    }
}
