<?php

namespace app\admin\controller\notify;

use app\common\controller\Backend;

/**
 * 通知模板
 */
class Template extends Backend
{
    /**
     * @var object
     * @phpstan-var \app\admin\model\notify\Template
     */
    protected object $model;

    protected string|array $defaultSortField = 'weigh,desc';

    protected array|string $preExcludeFields = ['id', 'create_time', 'update_time'];

    protected string|array $quickSearchField = ['name', 'key'];

    // key 由种子固定，不允许新增/删除，只允许编辑
    protected array $noNeedPermission = [];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new \app\admin\model\notify\Template();
    }
}
