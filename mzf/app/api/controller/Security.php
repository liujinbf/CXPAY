<?php

namespace app\api\controller;

use Throwable;
use think\facade\Cache;
use app\common\controller\Frontend;
use app\common\library\Totp;
use app\common\library\QrImage;
use app\common\model\User;

/**
 * 会员安全设置：谷歌验证(TOTP 2FA) 绑定/启用/关闭 + 登录二次验证。
 */
class Security extends Frontend
{
    // verify2fa 本身不能被 2FA 门禁拦截（它就是用来通过 2FA 的）
    protected array $noNeed2FA = ['verify2fa'];

    public function initialize(): void
    {
        parent::initialize();
    }

    /** 当前谷歌验证状态 */
    public function info(): void
    {
        $u = User::find($this->auth->id);
        $this->success('', [
            'enable' => (int) ($u->google_enable ?? 0) === 1,
            'bound'  => !empty($u->google_secret),
        ]);
    }

    /** 生成待绑定密钥 + 二维码（存缓存，10 分钟内有效） */
    public function bindQr(): void
    {
        $secret = Totp::genSecret();
        Cache::set('totp_pending:' . $this->auth->id, $secret, 600);
        $u    = User::find($this->auth->id);
        $uri  = Totp::keyUri($secret, (string) ($u->username ?: $u->id), (string) (get_sys_config('site_name') ?: 'xlpay'));
        $this->success('', [
            'secret' => $secret,
            'uri'    => $uri,
            'qr'     => QrImage::dataUri($uri),
        ]);
    }

    /** 校验动态码并启用 */
    public function bind(): void
    {
        $code   = (string) $this->request->post('code', '');
        $secret = (string) Cache::get('totp_pending:' . $this->auth->id);
        if (!$secret) {
            $this->error('绑定已过期，请重新获取二维码');
        }
        if (!Totp::verify($secret, $code)) {
            $this->error('验证码错误');
        }
        User::where('id', $this->auth->id)->update(['google_secret' => $secret, 'google_enable' => 1]);
        Cache::delete('totp_pending:' . $this->auth->id);
        // 绑定即视为本 token 已通过 2FA
        $this->markPassed();
        $this->success('谷歌验证已开启');
    }

    /** 关闭谷歌验证（需当前动态码确认） */
    public function disable(): void
    {
        $code = (string) $this->request->post('code', '');
        $u    = User::find($this->auth->id);
        if ((int) $u->google_enable !== 1) {
            $this->success('已是关闭状态');
        }
        if (!Totp::verify((string) $u->google_secret, $code)) {
            $this->error('验证码错误');
        }
        User::where('id', $this->auth->id)->update(['google_enable' => 0, 'google_secret' => '']);
        $this->success('谷歌验证已关闭');
    }

    /** 登录后二次验证：通过则标记本 token 已过 2FA */
    public function verify2fa(): void
    {
        $code = (string) $this->request->post('code', '');
        $u    = User::find($this->auth->id);
        if ((int) $u->google_enable !== 1) {
            $this->success('无需验证');
        }
        if (!Totp::verify((string) $u->google_secret, $code)) {
            $this->error('验证码错误');
        }
        $this->markPassed();
        $this->success('验证通过');
    }

    /** 标记当前 token 已通过 2FA */
    protected function markPassed(): void
    {
        $token = get_auth_token(['ba', 'user', 'token']);
        if ($token) {
            try {
                Cache::set('2fa_ok:' . $token, 1, 86400 * 30);
            } catch (Throwable $e) {
            }
        }
    }
}
