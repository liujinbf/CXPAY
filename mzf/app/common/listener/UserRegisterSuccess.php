<?php

namespace app\common\listener;

use app\common\library\notify\NotifyService;

class UserRegisterSuccess
{
    public function handle($user): void
    {
        if (!$user) return;
        $arr = is_object($user) ? $user->toArray() : $user;
        // 给用户发注册欢迎
        NotifyService::send('user_register', $arr);
        // 通知站长有新用户注册
        NotifyService::sendAdminRegister($arr);
    }
}
