<?php

namespace app\api\controller;

use think\facade\Db;
use think\facade\Cache;
use app\common\controller\Frontend;
use app\common\model\User;
use app\common\library\notify\WxPusher;
use app\common\library\notify\NotifyService;

/**
 * 会员通知设置：绑定 WxPusher + 各事件邮件/微信开关 + 余额预警阈值。
 */
class Notify extends Frontend
{
    public function initialize(): void
    {
        parent::initialize();
    }

    /** 通知设置总览 */
    public function info(): void
    {
        $u = User::find($this->auth->id);

        $userSwitch = [];
        $raw = $u->notify_switch ?? '';
        if ($raw) {
            $userSwitch = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        }
        $defaults = NotifyService::defaults();

        // 从模板表取启用事件（用户可控的）
        // user_register 在注册瞬间即发出，用户无从预设，属后台模板通知，前台不展示
        $hidden = ['user_register'];
        $tpls   = Db::name('notify_template')->where('status', 1)->whereNotIn('key', $hidden)->order('weigh', 'desc')->select();
        $events = [];
        foreach ($tpls as $t) {
            $key = $t['key'];
            $def = $defaults[$key] ?? ['email' => 1, 'wxpush' => 1];
            $one = $userSwitch[$key] ?? [];
            $events[] = [
                'key'           => $key,
                'name'          => $t['name'],
                'email_allowed' => (int) $t['email_enable'] === 1,
                'wxpush_allowed' => (int) $t['wxpush_enable'] === 1,
                'email'         => array_key_exists('email', $one) ? (int) $one['email'] : $def['email'],
                'wxpush'        => array_key_exists('wxpush', $one) ? (int) $one['wxpush'] : $def['wxpush'],
            ];
        }

        $this->success('', [
            'email'          => (string) $u->email,
            'wxpusher_bound' => !empty($u->wxpusher_uid),
            'wxpush_ready'   => (string) get_sys_config('wxpusher_apptoken') !== '',
            'money_edin'     => (float) $u->money_edin,
            'events'         => $events,
        ]);
    }

    /** 生成 WxPusher 关注二维码 */
    public function wxpusherQr(): void
    {
        $wp = new WxPusher();
        if (!$wp->configured()) {
            $this->error('站点未配置 WxPusher，请联系管理员');
        }
        $res = $wp->qrcode('u' . $this->auth->id);
        $code = $res['data']['code'] ?? '';
        $url  = $res['data']['url'] ?? '';
        if (!$code || !$url) {
            $this->error('二维码生成失败，请稍后再试');
        }
        // 记录 code 以便轮询
        Cache::set('wxpusher_qr:' . $this->auth->id, $code, 600);
        $this->success('', ['code' => $code, 'url' => $url]);
    }

    /** 轮询：扫码关注后回填 UID */
    public function wxpusherBind(): void
    {
        $code = (string) $this->request->post('code', '');
        if (!$code) $this->error('参数错误');
        $wp  = new WxPusher();
        $res = $wp->qrcodeUid($code);
        $uid = $res['data'] ?? '';
        if (!$uid) {
            // 尚未扫码/未关注
            $this->success('', ['bound' => false]);
        }
        User::where('id', $this->auth->id)->update(['wxpusher_uid' => $uid]);
        Cache::delete('wxpusher_qr:' . $this->auth->id);
        $this->success('绑定成功', ['bound' => true]);
    }

    /** 解绑 WxPusher */
    public function wxpusherUnbind(): void
    {
        User::where('id', $this->auth->id)->update(['wxpusher_uid' => '']);
        $this->success('已解绑');
    }

    /** 保存各事件开关 */
    public function saveSwitch(): void
    {
        $switch = $this->request->post('switch/a', []);
        $clean  = [];
        foreach ($switch as $key => $one) {
            $clean[$key] = [
                'email'  => !empty($one['email']) ? 1 : 0,
                'wxpush' => !empty($one['wxpush']) ? 1 : 0,
            ];
        }
        User::where('id', $this->auth->id)->update(['notify_switch' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
        $this->success('已保存');
    }

    /** 保存余额预警阈值（元）；改动后重置预警标志以便再次触发 */
    public function saveEdin(): void
    {
        $edin = (float) $this->request->post('money_edin', 0);
        if ($edin < 0) $edin = 0;
        User::where('id', $this->auth->id)->update(['money_edin' => $edin, 'status_edin' => 0]);
        $this->success('已保存');
    }
}
