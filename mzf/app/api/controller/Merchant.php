<?php

namespace app\api\controller;

use app\common\controller\Frontend;
use app\admin\model\User;
use app\common\model\PayPackvip;
use app\common\library\payment\CashierTemplate;

/**
 * 商户中心 - 商户资料/对接信息
 *
 * 商户即会员（ba_user）：支付字段(pay_key/asst_key/packvip/quota…)已并入 ba_user。
 * 所有接口以 $this->auth->id（即商户 PID）限定本商户。
 */
class Merchant extends Frontend
{
    protected array $noNeedLogin = [];

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 本商户概览 + 对接信息
     */
    public function info(): void
    {
        $user = User::find($this->auth->id);
        $this->ensureKeys($user);

        $packvip = $user->packvip_id ? PayPackvip::where('id', $user->packvip_id)->find() : null;

        $host    = $this->request->host(true);
        $scheme  = $this->request->scheme();
        $apiBase = $scheme . '://' . $host;
        $pt      = (int) $user->packvip_time;

        $this->success('', [
            'username'      => $user->username,
            'nickname'      => $user->nickname,
            'balance'       => $user->money,          // 元
            'pid'           => $user->pid,            // 商户对接PID（随机10位）
            'pay_key'       => $user->pay_key,
            'asst_key'      => $user->asst_key,
            'channel_quota' => $user->channel_quota,
            'packvip_name'  => $packvip->name ?? '未开通',
            'packvip_rate'  => $packvip->rate ?? null,
            'packvip_time'  => $pt ? date('Y-m-d H:i:s', $pt) : '',
            'is_vip'        => $pt > time(),
            'docking'       => [
                'gateway_url' => $apiBase . '/',            // 接口地址(网址)：多数易支付系统只填此项，自动拼 submit.php/mapi.php
                'submit_url'  => $apiBase . '/submit.php',   // 页面跳转支付
                'mapi_url'    => $apiBase . '/mapi.php',     // API 接口支付
                'api_url'     => $apiBase . '/api.php',      // 订单查询
                'notify_doc'  => '异步回调将 POST/GET 商户 notify_url，含 sign=MD5(参数+pay_key)',
            ],
        ]);
    }

    /**
     * 重置对接密钥
     */
    public function resetKey(): void
    {
        $user = User::find($this->auth->id);
        $user->pay_key = bin2hex(random_bytes(16));
        $user->save();
        $this->success('已重置对接密钥', ['pay_key' => $user->pay_key]);
    }

    /**
     * 重置软件密钥（挂机/软件端对接鉴权）
     */
    public function resetAsstKey(): void
    {
        $user = User::find($this->auth->id);
        $user->asst_key = bin2hex(random_bytes(12));
        $user->save();
        $this->success('已重置软件密钥', ['asst_key' => $user->asst_key]);
    }

    /**
     * 通道配置（收银台/收款设置）读取
     */
    public function payConfig(): void
    {
        $user = User::find($this->auth->id);
        $outtime = (int) $user->pay_outtime ?: 180;
        $this->success('', [
            'pay_notice'       => (string) $user->pay_notice,
            'pay_outtime'      => min(300, max(60, $outtime)),
            'paypage'          => CashierTemplate::resolveForUser((string) $user->paypage),
            'mapi_return_mode' => (string) $user->mapi_return_mode ?: 'payurl',
            'pay_jump_type'    => (int) $user->pay_jump_type,
            'pay_jump_url'     => (string) $user->pay_jump_url,
            'pay_float_min'    => (string) $user->pay_float_min,
            'pay_float_max'    => (string) $user->pay_float_max,
            'templates'        => CashierTemplate::enabledList(),
        ]);
    }

    /**
     * 通道配置保存
     */
    public function savePayConfig(): void
    {
        if (!$this->request->isPost()) {
            $this->error('请求方式错误');
        }
        $notice   = (string) $this->request->param('pay_notice', '');
        $outtime  = (int) $this->request->param('pay_outtime', 180);
        $paypage  = (string) $this->request->param('paypage', CashierTemplate::DEFAULT);
        $mapiMode = (string) $this->request->param('mapi_return_mode', 'payurl');
        $jumpType = (int) $this->request->param('pay_jump_type', 0);
        $jumpUrl  = trim((string) $this->request->param('pay_jump_url', ''));
        $floatMin = (float) $this->request->param('pay_float_min', 0);
        $floatMax = (float) $this->request->param('pay_float_max', 0);

        if (mb_strlen($notice) > 300) {
            $this->error('支付公告不能超过 300 字');
        }
        $outtime = min(300, max(60, $outtime)); // 60~300 秒（最多 5 分钟）
        if (!in_array($paypage, CashierTemplate::enabledKeys(), true)) {
            $this->error('支付模板不存在或未启用');
        }
        if (!in_array($mapiMode, ['payurl', 'qrcode'], true)) {
            $this->error('mapi 返回模式不合法');
        }
        if (!in_array($jumpType, [0, 1], true)) {
            $this->error('跳转方式不合法');
        }
        if ($jumpType === 1) {
            if ($jumpUrl === '' || !preg_match('#^https?://#i', $jumpUrl)) {
                $this->error('自定义跳转地址需以 http(s):// 开头');
            }
        } else {
            $jumpUrl = '';
        }
        if ($floatMin < 0 || $floatMax < 0 || $floatMin > $floatMax || $floatMax > 9.99) {
            $this->error('上浮金额需满足 0 ≤ 最小 ≤ 最大 ≤ 9.99 元');
        }

        $user = User::find($this->auth->id);
        $user->pay_notice       = $notice;
        $user->pay_outtime      = $outtime;
        $user->paypage          = $paypage;
        $user->mapi_return_mode = $mapiMode;
        $user->pay_jump_type    = $jumpType;
        $user->pay_jump_url     = $jumpUrl;
        $user->pay_float_min    = number_format($floatMin, 2, '.', '');
        $user->pay_float_max    = number_format($floatMax, 2, '.', '');
        $user->save();

        $this->success('保存成功');
    }

    /**
     * 确保 pay_key/asst_key 存在（历史会员补齐）
     */
    protected function ensureKeys(User $user): void
    {
        $dirty = false;
        if (empty($user->pay_key)) {
            $user->pay_key = bin2hex(random_bytes(16));
            $dirty = true;
        }
        if (empty($user->asst_key)) {
            $user->asst_key = bin2hex(random_bytes(12));
            $dirty = true;
        }
        if (empty($user->pid)) {
            $user->pid = User::generatePid();
            $dirty = true;
        }
        if ($dirty) {
            $user->save();
        }
    }
}
