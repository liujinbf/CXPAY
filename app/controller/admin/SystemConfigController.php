<?php

declare(strict_types=1);

namespace app\controller\admin;

use support\Request;
use support\Response;
use Illuminate\Database\Capsule\Manager as DB;
use app\model\Merchant;
use app\model\Channel;
use Throwable;

/**
 * 管理后台系统运营配置（新用户注册赠送余额、系统收款PID、云端插件商城支付配置等）
 */
class SystemConfigController
{
    /**
     * 获取系统运营与插件支付配置 GET /api/admin/system/config
     */
    public function getConfig(Request $request): Response
    {
        try {
            $configs = DB::table('cx_config')
                ->whereIn('name', [
                    'register_grant_balance',
                    'system_recharge_pid',
                    'site_name',
                    'active_home_template',
                    'plugin_payment_mode',
                    'plugin_payment_channel_wx',
                    'plugin_payment_channel_ali',
                ])
                ->pluck('value', 'name');

            $systemPid = (string)($configs['system_recharge_pid'] ?? '1000');
            $merchant = Merchant::where('pid', $systemPid)->first();
            $channels = [];
            if ($merchant) {
                $channels = Channel::where('merchant_id', $merchant->id)
                    ->where('status', 1)
                    ->select(['id', 'title', 'c_type', 'pay_category', 'status'])
                    ->get()
                    ->toArray();
            }

            return json([
                'code' => 1,
                'data' => [
                    'register_grant_balance'     => (string)($configs['register_grant_balance'] ?? '10.00'),
                    'system_recharge_pid'        => $systemPid,
                    'site_name'                  => (string)($configs['site_name'] ?? 'CXPAY 聚合支付网关'),
                    'active_home_template'       => (string)($configs['active_home_template'] ?? 'default'),
                    'plugin_payment_mode'        => (string)($configs['plugin_payment_mode'] ?? 'system_channel'),
                    'plugin_payment_channel_wx'  => (string)($configs['plugin_payment_channel_wx'] ?? '0'),
                    'plugin_payment_channel_ali' => (string)($configs['plugin_payment_channel_ali'] ?? '0'),
                    'system_merchant'            => $merchant ? [
                        'id'       => $merchant->id,
                        'pid'      => $merchant->pid,
                        'username' => $merchant->username,
                        'channels' => $channels,
                    ] : null,
                ]
            ]);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '获取配置失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 保存系统运营与插件支付配置 POST /api/admin/system/config/save
     */
    public function saveConfig(Request $request): Response
    {
        try {
            $grantBalance = (float)($request->post('register_grant_balance', 10.00));
            $systemPid    = trim((string)$request->post('system_recharge_pid', '1000'));
            $siteName     = trim((string)$request->post('site_name', ''));
            $template     = trim((string)$request->post('active_home_template', ''));
            $payMode      = trim((string)$request->post('plugin_payment_mode', 'system_channel'));
            $channelWx    = trim((string)$request->post('plugin_payment_channel_wx', '0'));
            $channelAli   = trim((string)$request->post('plugin_payment_channel_ali', '0'));

            if ($grantBalance < 0) {
                return json(['code' => -1, 'msg' => '注册赠送体验金额不能为负数']);
            }
            if ($systemPid === '') {
                return json(['code' => -1, 'msg' => '平台系统收款 PID 不能为空']);
            }
            if ($template !== '' && !preg_match('/^[A-Za-z0-9_-]{1,50}$/', $template)) {
                return json(['code' => -1, 'msg' => '主页模板名称不合法']);
            }

            // 更新或插入数据库
            $items = [
                'register_grant_balance'     => number_format($grantBalance, 2, '.', ''),
                'system_recharge_pid'        => $systemPid,
                'plugin_payment_mode'        => $payMode,
                'plugin_payment_channel_wx'  => $channelWx,
                'plugin_payment_channel_ali' => $channelAli,
            ];
            if ($siteName !== '') {
                $items['site_name'] = $siteName;
            }
            if ($template !== '') {
                $items['active_home_template'] = $template;
            }

            foreach ($items as $name => $value) {
                DB::table('cx_config')->updateOrInsert(
                    ['name' => $name],
                    ['value' => $value]
                );
            }

            return json(['code' => 1, 'msg' => '系统运营与支付配置保存成功']);
        } catch (Throwable $e) {
            return json(['code' => -1, 'msg' => '保存配置失败: ' . $e->getMessage()]);
        }
    }
}

