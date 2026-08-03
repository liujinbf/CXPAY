<?php

declare(strict_types=1);

namespace app\controller\admin;

use support\Request;
use support\Response;
use Illuminate\Database\Capsule\Manager as DB;
use Throwable;

/**
 * 管理后台系统运营配置（新用户注册赠送余额、系统收款PID等）
 */
class SystemConfigController
{
    /**
     * 获取系统运营配置 GET /api/admin/system/config
     */
    public function getConfig(Request $request): Response
    {
        try {
            $configs = DB::table('cx_config')
                ->whereIn('name', ['register_grant_balance', 'system_recharge_pid', 'site_name'])
                ->pluck('value', 'name');

            return json([
                'code' => 1,
                'data' => [
                    'register_grant_balance' => (string)($configs['register_grant_balance'] ?? '10.00'),
                    'system_recharge_pid'    => (string)($configs['system_recharge_pid'] ?? '1000'),
                    'site_name'             => (string)($configs['site_name'] ?? 'CXPAY 聚合支付网关'),
                ]
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '获取配置失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 保存系统运营配置 POST /api/admin/system/config/save
     */
    public function saveConfig(Request $request): Response
    {
        try {
            $grantBalance = (float)($request->post('register_grant_balance', 10.00));
            $systemPid    = trim((string)$request->post('system_recharge_pid', '1000'));
            $siteName     = trim((string)$request->post('site_name', ''));

            if ($grantBalance < 0) {
                return json(['code' => -1, 'msg' => '注册赠送体验金额不能为负数']);
            }
            if ($systemPid === '') {
                return json(['code' => -1, 'msg' => '平台系统收款 PID 不能为空']);
            }

            // 更新或插入数据库
            $items = [
                'register_grant_balance' => number_format($grantBalance, 2, '.', ''),
                'system_recharge_pid'    => $systemPid,
            ];
            if ($siteName !== '') {
                $items['site_name'] = $siteName;
            }

            foreach ($items as $name => $value) {
                DB::table('cx_config')->updateOrInsert(
                    ['name' => $name],
                    ['value' => $value]
                );
            }

            return json(['code' => 1, 'msg' => '系统运营与交易配置保存成功！']);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '保存配置失败: ' . $e->getMessage()]);
        }
    }
}
