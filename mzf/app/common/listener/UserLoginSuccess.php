<?php

namespace app\common\listener;

use app\common\library\notify\NotifyService;

class UserLoginSuccess
{
    public function handle($user): void
    {
        if (!$user) return;
        NotifyService::send('user_login', is_object($user) ? $user->toArray() : $user, [
            '[ip]' => request()->ip(),
        ]);
    }
}
