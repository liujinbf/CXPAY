<?php

namespace app\common\controller;

use Throwable;
use think\facade\Event;
use app\common\library\Auth;
use think\exception\HttpResponseException;
use app\common\library\token\TokenExpirationException;

class Frontend extends Api
{
    /**
     * 无需登录的方法
     * 访问本控制器的此方法，无需会员登录
     * @var array
     */
    protected array $noNeedLogin = [];

    /**
     * 无需鉴权的方法
     * @var array
     */
    protected array $noNeedPermission = [];

    /**
     * 无需通过谷歌验证(2FA)即可访问的方法（如二次验证接口本身、登出）
     * @var array
     */
    protected array $noNeed2FA = [];

    /**
     * 权限类实例
     * @var Auth
     */
    protected Auth $auth;

    /**
     * 初始化
     * @throws Throwable
     * @throws HttpResponseException
     */
    public function initialize(): void
    {
        parent::initialize();

        $needLogin = !action_in_arr($this->noNeedLogin);

        try {

            // 初始化会员鉴权实例
            $this->auth = Auth::instance();
            $token      = get_auth_token(['ba', 'user', 'token']);
            if ($token) $this->auth->init($token);

        } catch (TokenExpirationException) {
            if ($needLogin) {
                $this->error(__('Token expiration'), [], 409);
            }
        }

        if ($needLogin) {
            if (!$this->auth->isLogin()) {
                $this->error(__('Please login first'), [
                    'type' => $this->auth::NEED_LOGIN
                ], $this->auth::LOGIN_RESPONSE_CODE);
            }
            if (!action_in_arr($this->noNeedPermission)) {
                $routePath = ($this->app->request->controllerPath ?? '') . '/' . $this->request->action(true);
                if (!$this->auth->check($routePath)) {
                    $this->error(__('You have no permission'), [], 401);
                }
            }

            // 谷歌验证(2FA)强制：启用的会员在本次登录未通过 TOTP 前，除放行接口外一律拒绝
            if (!action_in_arr($this->noNeed2FA)) {
                $enable = (int) \app\common\model\User::where('id', $this->auth->id)->value('google_enable');
                if ($enable === 1) {
                    $token = get_auth_token(['ba', 'user', 'token']);
                    $passed = true;
                    try {
                        $passed = $token && \think\facade\Cache::get('2fa_ok:' . $token);
                    } catch (Throwable $e) {
                        $passed = true; // 缓存不可用则降级放行，避免全站死锁
                    }
                    if (!$passed) {
                        $this->error('需要二次验证', ['type' => 'need2FA'], 4010);
                    }
                }
            }
        }

        // 会员验权和登录标签位
        Event::trigger('frontendInit', $this->auth);
    }
}