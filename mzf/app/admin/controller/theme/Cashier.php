<?php

namespace app\admin\controller\theme;

use app\common\controller\Backend;
use app\common\library\payment\CashierTemplate;
use app\common\model\ThemeSetting;

/**
 * 主题设置 → 支付模板（收银台）
 * 管平台默认 + 启用/停用 + 预览。商户中心自选模板仅从「启用」集中挑。
 * 模板自动加载自 app/common/library/theme/cashier_templates/*.php。
 */
class Cashier extends Backend
{
    protected array $noNeedLogin = [];

    /** 模板列表 + 平台默认 + 启用集 */
    public function index(): void
    {
        $this->success('', [
            'templates' => CashierTemplate::list(),
            'default'   => CashierTemplate::defaultKey(),
            'enabled'   => CashierTemplate::enabledKeys(),
        ]);
    }

    /** 保存：平台默认 + 启用集 */
    public function save(): void
    {
        $default = (string) $this->request->post('default', '');
        $enabled = $this->request->post('enabled/a', []);

        $keys = CashierTemplate::keys();
        // 过滤非法 key
        $enabled = array_values(array_intersect(is_array($enabled) ? $enabled : [], $keys));
        if (!$enabled) {
            $this->error('至少启用一套支付模板');
        }
        if (!in_array($default, $enabled, true)) {
            $this->error('默认模板必须在已启用的模板中');
        }

        ThemeSetting::setVal('cashier.enabled', json_encode($enabled, JSON_UNESCAPED_UNICODE));
        ThemeSetting::setVal('cashier.default', $default);
        $this->success('保存成功');
    }
}
