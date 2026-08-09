<?php

declare(strict_types=1);

namespace app\controller\admin;

use Illuminate\Database\Capsule\Manager as DB;
use support\Authcode;

/**
 * 管理员二次验证与密码安全设置控制器
 */
final class AdminSecurityController
{
    protected Authcode $authcode;

    public function __construct()
    {
        $this->authcode = new Authcode();
    }

    /**
     * 获取管理员安全设置（二次验证码状态、Token 版本号）
     *
     * GET /api/admin/security/config
     * 敏感字段（验证码明文）不下发，仅告知是否已配置。
     */
    public function getSecurityConfig(\support\Request $request): string
    {
        $verifyEnabled = (int)DB::table('cx_config')
            ->where('name', 'admin_verify_code_enabled')->value('value');

        $storedEncrypted = (string)DB::table('cx_config')
            ->where('name', 'admin_verify_code')->value('value');

        $tokenVersion = (int)(DB::table('cx_config')
            ->where('name', 'admin_token_version')->value('value') ?: 1);

        return json_encode([
            'code' => 1,
            'data' => [
                'verify_enabled'    => $verifyEnabled === 1,
                'verify_configured' => $storedEncrypted !== '',
                'token_version'     => $tokenVersion,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 保存管理员安全设置（二次验证码开关/内容、管理员密码修改）
     *
     * POST /api/admin/security/config/save
     * Body 参数（均可选，仅提交需变更的字段）：
     *   verify_enabled   : 0|1  — 二次验证码开关
     *   verify_code      : string — 新静态验证码（为空则保留原值）
     *   new_password     : string — 新管理员登录密码（为空则不修改）
     *   current_password : string — 修改密码时必须提供旧密码
     */
    public function saveSecurityConfig(\support\Request $request): string
    {
        $params = $request->post();

        // ── 1. 二次验证码开关 ─────────────────────────────────────────
        if (array_key_exists('verify_enabled', $params)) {
            $enabled = (int)($params['verify_enabled'] ?? 0) === 1 ? '1' : '0';
            DB::table('cx_config')
                ->where('name', 'admin_verify_code_enabled')
                ->update(['value' => $enabled]);
        }

        // ── 2. 更新静态验证码 ─────────────────────────────────────────
        $newCode = trim((string)($params['verify_code'] ?? ''));
        if ($newCode !== '') {
            if (strlen($newCode) < 4 || strlen($newCode) > 32) {
                return json_encode(['code' => -1, 'msg' => '验证码长度须在4至32位之间'], JSON_UNESCAPED_UNICODE);
            }
            $encrypted = $this->authcode->encrypt($newCode);
            DB::table('cx_config')
                ->where('name', 'admin_verify_code')
                ->update(['value' => $encrypted]);
        }

        // ── 3. 修改管理员登录密码 ─────────────────────────────────────
        $newPassword     = (string)($params['new_password'] ?? '');
        $currentPassword = (string)($params['current_password'] ?? '');
        if ($newPassword !== '') {
            if (strlen($newPassword) < 6 || strlen($newPassword) > 200) {
                return json_encode(['code' => -1, 'msg' => '新密码长度至少为6个字符'], JSON_UNESCAPED_UNICODE);
            }
            if ($currentPassword === '') {
                return json_encode(['code' => -1, 'msg' => '修改密码时必须提供当前密码'], JSON_UNESCAPED_UNICODE);
            }

            // 校验当前密码
            $storedHash = (string)DB::table('cx_config')
                ->where('name', 'admin_password_hash')->value('value');
            if (!password_verify($currentPassword, $storedHash)) {
                return json_encode(['code' => -1, 'msg' => '当前管理员密码错误'], JSON_UNESCAPED_UNICODE);
            }

            // 更新密码哈希
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            DB::table('cx_config')
                ->where('name', 'admin_password_hash')
                ->update(['value' => $newHash, 'title' => '管理员密码Bcrypt哈希']);

            // 递增 Token 版本号，使所有已颁发的旧 Token 立即失效
            $currentVersion = (int)(DB::table('cx_config')
                ->where('name', 'admin_token_version')->value('value') ?: 1);
            DB::table('cx_config')
                ->where('name', 'admin_token_version')
                ->update(['value' => (string)($currentVersion + 1)]);

            // 同步清除当前 Session，要求重新登录
            $request->session()->forget('admin_info');
        }

        return json_encode(['code' => 1, 'msg' => '安全设置已保存'], JSON_UNESCAPED_UNICODE);
    }
}
