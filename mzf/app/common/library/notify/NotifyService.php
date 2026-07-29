<?php

namespace app\common\library\notify;

use Throwable;
use think\facade\Db;
use app\common\library\Email;

/**
 * 通知服务：按模板 + 用户开关 分发 邮件 / WxPush
 * best-effort：任何失败都不影响主流程/资金链路（全 try/catch + 紧超时）。
 */
class NotifyService
{
    /**
     * 各事件的 per-user 默认开关（用户未自定义时生效）
     * 支付/回调/建改通道默认关（防噪 + 防结算链路拖慢），其余默认开。
     */
    protected static array $defaults = [
        'user_login'      => ['email' => 1, 'wxpush' => 1],
        'user_register'   => ['email' => 1, 'wxpush' => 1],
        'pwd_reset'       => ['email' => 1, 'wxpush' => 1],
        'low_balance'     => ['email' => 1, 'wxpush' => 1],
        'channel_offline' => ['email' => 1, 'wxpush' => 1],
        'order_paid'      => ['email' => 0, 'wxpush' => 0],
        'order_callback'  => ['email' => 0, 'wxpush' => 0],
        'channel_create'  => ['email' => 0, 'wxpush' => 0],
        'channel_update'  => ['email' => 0, 'wxpush' => 0],
    ];

    /**
     * 各事件的 per-user 默认开关（供前台展示缺省态）
     */
    public static function defaults(): array
    {
        return self::$defaults;
    }

    /**
     * 发送某事件通知给指定用户
     * @param string    $key    模板 key
     * @param int|array $user   用户 id 或用户数组（含 email/wxpusher_uid/notify_switch/username）
     * @param array     $vars   占位符变量（不含 [sitename][date]，会自动补）
     */
    public static function send(string $key, $user, array $vars = []): void
    {
        try {
            $tpl = Db::name('notify_template')->where('key', $key)->where('status', 1)->find();
            if (!$tpl) return;

            $user = is_array($user) ? $user : Db::name('user')->where('id', (int)$user)->find();
            if (!$user) return;

            $sw    = self::userSwitch($user, $key);
            $email = !empty($tpl['email_enable']) && $sw['email'];
            $wx    = !empty($tpl['wxpush_enable']) && $sw['wxpush'];
            if (!$email && !$wx) return;

            $vars    = self::baseVars($user) + $vars;
            $subject = self::render($tpl['subject'], $vars);
            $content = self::render($tpl['content'], $vars);

            if ($email && !empty($user['email'])) {
                self::mail($user['email'], $subject, $content);
            }
            if ($wx && !empty($user['wxpusher_uid'])) {
                self::wxpush($user['wxpusher_uid'], $subject, $content);
            }
        } catch (Throwable) {
            // 通知失败绝不影响主流程
        }
    }

    /**
     * 新用户注册 → 通知站长（后台独立双开关）
     */
    public static function sendAdminRegister(array $newUser): void
    {
        try {
            $sitename = (string)get_sys_config('site_name');
            $username = $newUser['username'] ?? ($newUser['nickname'] ?? '新用户');
            $subject  = "新用户注册-{$sitename}";
            $content  = "<h2>{$sitename} 新用户注册</h2><p>账号：{$username}</p>"
                . "<p>邮箱：" . ($newUser['email'] ?? '-') . "</p>"
                . "<p>时间：" . date('Y-m-d H:i:s') . "</p>";

            if (get_sys_config('notify_admin_register_email')) {
                $to = (string)get_sys_config('notify_admin_email');
                if ($to) self::mail($to, $subject, $content);
            }
            if (get_sys_config('notify_admin_register_wxpush')) {
                $uid = (string)get_sys_config('notify_admin_wxpush_uid');
                if ($uid) self::wxpush($uid, $subject, $content);
            }
        } catch (Throwable) {
        }
    }

    /**
     * 解析用户对某事件的开关（缺省用模板默认）
     * @return array{email:int,wxpush:int}
     */
    protected static function userSwitch(array $user, string $key): array
    {
        $def = self::$defaults[$key] ?? ['email' => 1, 'wxpush' => 1];
        $raw = $user['notify_switch'] ?? '';
        if (!$raw) return $def;
        $all = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
        $one = $all[$key] ?? [];
        return [
            'email'  => array_key_exists('email', $one) ? (int)$one['email'] : $def['email'],
            'wxpush' => array_key_exists('wxpush', $one) ? (int)$one['wxpush'] : $def['wxpush'],
        ];
    }

    protected static function baseVars(array $user): array
    {
        return [
            '[sitename]' => (string)get_sys_config('site_name'),
            '[username]' => $user['username'] ?? ($user['nickname'] ?? ''),
            '[date]'     => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 通道展示名（对应支付图标 + 通道类型名 + 备注），供各通道相关通知的 [channel]。
     * @param string $type  支付方式 wxpay/alipay/qqpay（取图标）
     * @param string $cType 具体通道 c_type（取名称）
     * @param string $notes 通道备注（可选）
     */
    public static function channelLabel(string $type, string $cType, string $notes = ''): string
    {
        $name = (string) Db::name('pay_ctype')->where('c_type', $cType)->value('name');
        if ($name === '') {
            $name = $cType;
        }
        if ($notes !== '') {
            $name .= ' · ' . $notes;
        }
        $icon = PayIcons::icon($type);
        return $icon !== '' ? ($icon . $name) : $name;
    }

    protected static function render(string $tpl, array $vars): string
    {
        return str_replace(array_keys($vars), array_values($vars), $tpl);
    }

    /**
     * 从邮箱轮询池挑一个发件账号（轮询，避免单邮箱频繁发送被限流）。
     * 池为空 → 返回 null（回落到单个 SMTP 配置）。
     */
    protected static function pickMailAccount(): ?array
    {
        $raw  = get_sys_config('mail_pool');
        $pool = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $valid = [];
        foreach ((array) $pool as $a) {
            if (!empty($a['server']) && !empty($a['user']) && !empty($a['pass'])) {
                $valid[] = $a;
            }
        }
        if (!$valid) {
            return null;
        }
        if (count($valid) === 1) {
            return $valid[0];
        }
        // 轮询指针（缓存计数），失败则随机
        try {
            $ptr = (int) \think\facade\Cache::get('mail_pool_ptr', 0);
            \think\facade\Cache::set('mail_pool_ptr', $ptr + 1, 86400);
            return $valid[$ptr % count($valid)];
        } catch (Throwable) {
            return $valid[array_rand($valid)];
        }
    }

    protected static function mail(string $to, string $subject, string $html): void
    {
        try {
            $acct = self::pickMailAccount();
            $mail = new Email();
            if ($acct) {
                // 用轮询池账号覆盖 SMTP 连接
                $mail->configured = true;
                $mail->Host       = (string) $acct['server'];
                $mail->SMTPAuth   = true;
                $mail->Username   = (string) $acct['user'];
                $mail->Password   = (string) $acct['pass'];
                $mail->SMTPSecure = (($acct['secure'] ?? 'SSL') === 'SSL') ? 'ssl' : 'tls';
                $mail->Port       = (int) ($acct['port'] ?? 465) ?: 465;
                $mail->setFrom((string) ($acct['sender'] ?: $acct['user']), (string) $acct['user']);
            }
            if (!$mail->configured) return;
            $mail->isSMTP();
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->setSubject($subject);
            $mail->Body = $html;
            $mail->send();
        } catch (Throwable) {
        }
    }

    protected static function wxpush(string $uid, string $summary, string $html): void
    {
        try {
            $wp = new WxPusher();
            if (!$wp->configured()) return;
            $wp->send($uid, $summary, $html);
        } catch (Throwable) {
        }
    }
}
