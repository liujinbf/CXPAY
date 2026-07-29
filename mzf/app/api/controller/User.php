<?php

namespace app\api\controller;

use Throwable;
use ba\Captcha;
use ba\ClickCaptcha;
use think\facade\Config;
use app\common\facade\Token;
use app\common\controller\Frontend;
use app\api\validate\User as UserValidate;

class User extends Frontend
{
    protected array $noNeedLogin = ['checkIn', 'logout'];

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * 会员签入(登录和注册)
     * @throws Throwable
     */
    public function checkIn(): void
    {
        $openMemberCenter = Config::get('buildadmin.open_member_center');
        if (!$openMemberCenter) {
            $this->error(__('Member center disabled'));
        }

        // 检查登录态
        if ($this->auth->isLogin()) {
            $this->success(__('You have already logged in. There is no need to log in again~'), [
                'type' => $this->auth::LOGGED_IN
            ], $this->auth::LOGIN_RESPONSE_CODE);
        }

        $userLoginCaptchaSwitch = Config::get('buildadmin.user_login_captcha');

        if ($this->request->isPost()) {
            $params = $this->request->post(['tab', 'email', 'mobile', 'username', 'password', 'keep', 'captcha', 'captchaId', 'captchaInfo', 'registerType']);

            // 提前检查 tab ，然后将以 tab 值作为数据验证场景
            if (!in_array($params['tab'] ?? '', ['login', 'register'])) {
                $this->error(__('Unknown operation'));
            }

            $validate = new UserValidate();
            try {
                $validate->scene($params['tab'])->check($params);
            } catch (Throwable $e) {
                $this->error($e->getMessage());
            }

            if ($params['tab'] == 'login') {
                if ($userLoginCaptchaSwitch) {
                    $captchaObj = new ClickCaptcha();
                    if (!$captchaObj->check($params['captchaId'], $params['captchaInfo'])) {
                        $this->error(__('Captcha error'));
                    }
                }
                $res = $this->auth->login($params['username'], $params['password'], !empty($params['keep']));
            } elseif ($params['tab'] == 'register') {
                // 是否开启注册
                if (!get_sys_config('user_register_enable')) {
                    $this->error('注册已关闭');
                }
                // 注册验证开启才校验邮箱验证码
                if (get_sys_config('user_register_verify')) {
                    $captchaObj = new Captcha();
                    if (!$captchaObj->check($params['captcha'], $params[$params['registerType']] . 'user_register')) {
                        $this->error(__('Please enter the correct verification code'));
                    }
                }
                $res = $this->auth->register($params['username'], $params['password'], $params['mobile'], $params['email']);
                // 注册成功发放赠送（余额 / 套餐）
                if ($res === true) {
                    $this->grantRegisterGift((int) $this->auth->id);
                }
            }

            if (isset($res) && $res === true) {
                $this->success(__('Login succeeded!'), [
                    'userInfo'  => $this->auth->getUserInfo(),
                    'routePath' => '/user',
                    'need2FA'   => $params['tab'] == 'login' && (int) \app\common\model\User::where('id', $this->auth->id)->value('google_enable') === 1,
                ]);
            } else {
                $msg = $this->auth->getError();
                $msg = $msg ?: __('Check in failed, please try again or contact the website administrator~');
                $this->error($msg);
            }
        }

        $this->success('', [
            'userLoginCaptchaSwitch'  => $userLoginCaptchaSwitch,
            'userRegisterEnable'      => (bool) get_sys_config('user_register_enable'),
            'userRegisterVerify'      => (bool) get_sys_config('user_register_verify'),
            'accountVerificationType' => get_account_verification_type()
        ]);
    }

    /**
     * 注册赠送：按系统配置给新用户发放余额 / 会员套餐。失败仅记日志，不影响注册。
     */
    protected function grantRegisterGift(int $uid): void
    {
        if ($uid <= 0) {
            return;
        }
        $giftMoney   = (float) get_sys_config('user_register_gift_money');
        $giftPackvip = (int) get_sys_config('user_register_gift_packvip');
        if ($giftMoney <= 0 && $giftPackvip <= 0) {
            return;
        }
        \think\facade\Db::startTrans();
        try {
            // 赠送余额（money 以分存储）
            if ($giftMoney > 0) {
                $cents  = (int) round($giftMoney * 100);
                $before = (int) \think\facade\Db::name('user')->where('id', $uid)->value('money');
                $after  = $before + $cents;
                \think\facade\Db::name('user')->where('id', $uid)->update(['money' => $after]);
                \think\facade\Db::name('user_money_log')->insert([
                    'user_id'     => $uid,
                    'money'       => $cents,
                    'before'      => $before,
                    'after'       => $after,
                    'memo'        => '注册赠送余额',
                    'create_time' => time(),
                ]);
            }
            // 赠送会员套餐（按套餐自带天数计到期 + 同步通道配额）
            if ($giftPackvip > 0) {
                $pack = \app\common\model\PayPackvip::find($giftPackvip);
                if ($pack) {
                    \think\facade\Db::name('user')->where('id', $uid)->update([
                        'packvip_id'    => $pack->id,
                        'packvip_time'  => time() + (int) $pack->days * 86400,
                        'channel_quota' => (int) $pack->channel_quota, // 同步套餐的通道配额
                    ]);
                }
            }
            \think\facade\Db::commit();
        } catch (\Throwable $e) {
            \think\facade\Db::rollback();
            \think\facade\Log::error('[register] 赠送失败 uid=' . $uid . ' ' . $e->getMessage());
        }
    }

    public function logout(): void
    {
        if ($this->request->isPost()) {
            $refreshToken = $this->request->post('refreshToken', '');
            if ($refreshToken) Token::delete((string)$refreshToken);
            $this->auth->logout();
            $this->success();
        }
    }
}