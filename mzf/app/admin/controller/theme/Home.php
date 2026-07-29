<?php

namespace app\admin\controller\theme;

use app\common\controller\Backend;
use app\common\library\theme\HomeTemplate;
use app\common\model\ThemeSetting;

/**
 * 主题设置 → 主页模板
 * 仅切换网站首页皮肤：列出可选模板 + 当前激活 key；保存激活 key。
 * 模板自动加载自 app/common/library/theme/home_templates/*.php。
 */
class Home extends Backend
{
    protected array $noNeedLogin = [];

    /** 模板列表 + 当前激活 */
    public function index(): void
    {
        $this->success('', [
            'templates' => HomeTemplate::list(),
            'active'    => HomeTemplate::active(),
        ]);
    }

    /** 保存激活模板 */
    public function save(): void
    {
        $active = (string) $this->request->post('active', '');
        if (!in_array($active, HomeTemplate::keys(), true)) {
            $this->error('模板不存在');
        }
        ThemeSetting::setVal('home.active', $active);
        $this->success('保存成功');
    }
}
