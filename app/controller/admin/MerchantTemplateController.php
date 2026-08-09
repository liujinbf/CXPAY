<?php

declare(strict_types=1);

namespace app\controller\admin;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * 官网主页模板选择控制器
 */
final class MerchantTemplateController
{
    /**
     * 保存官网主页模版选择
     */
    public function saveTemplate(\support\Request $request): string
    {
        $params = $request->post();
        $templateName = trim((string)($params['template'] ?? 'default'));
        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $templateName)
            || !is_file(base_path() . "/public/home_templates/{$templateName}.html")) {
            return json_encode(['code' => -1, 'msg' => '主页模板不存在或名称不合法'], JSON_UNESCAPED_UNICODE);
        }

        DB::table('cx_config')
            ->updateOrInsert(
                ['name' => 'active_home_template'],
                ['value' => $templateName]
            );

        return json_encode(['code' => 1, 'msg' => '官网主页模版保存生效成功'], JSON_UNESCAPED_UNICODE);
    }
}
