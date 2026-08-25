<?php

declare(strict_types=1);

namespace app\controller\admin;

use Illuminate\Database\Capsule\Manager as DB;
use support\AuditLog;
use support\Request;
use support\Response;

/**
 * 子管理员 CRUD + 邀请链接控制器（P1）
 *
 * 路由（均受 AdminAuthMiddleware 保护，仅 root 可写）：
 *   GET  /api/admin/sub_admin/list
 *   POST /api/admin/sub_admin/save    — 新建或更新
 *   POST /api/admin/sub_admin/delete
 *   POST /api/admin/sub_admin/toggle  — 启用/禁用
 *   POST /api/admin/sub_admin/invite  — 生成一次性邀请链接（让被邀请者自行设置密码）
 */
final class SubAdminController
{
    // ── 权限守卫：仅 root 角色可执行写操作 ────────────────────────────────────
    private function requireRoot(Request $request): ?string
    {
        $role = (string)(($request->context['admin_info'] ?? [])['role'] ?? 'root');
        if ($role !== 'root') {
            return json_encode(['code' => 403, 'msg' => '仅超级管理员可管理子账号'], JSON_UNESCAPED_UNICODE);
        }
        return null;
    }

    // ── 查询列表 ──────────────────────────────────────────────────────────────
    public function list(Request $request): string
    {
        $page     = max(1, (int)$request->get('page', 1));
        $pageSize = min(50, max(10, (int)$request->get('page_size', 20)));

        $query = DB::table('cx_admin')->orderByDesc('id');

        $total = $query->count();
        $rows  = $query->forPage($page, $pageSize)->get([
            'id', 'username', 'role', 'display_name', 'status',
            'last_login_at', 'last_login_ip', 'create_time',
        ]);

        return json_encode([
            'code' => 1,
            'data' => [
                'list'      => $rows->map(fn($r) => (array)$r)->values(),
                'total'     => $total,
                'page'      => $page,
                'page_size' => $pageSize,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── 查询系统角色与权限列表 ───────────────────────────────────────────────
    public function roles(Request $request): string
    {
        $definedRoles = [
            [
                'role' => 'root',
                'name' => '超级管理员 (Root)',
                'description' => '拥有系统最高控制权，可管理所有通道、商户、子管理员及云端配置。',
                'is_system' => true,
            ],
            [
                'role' => 'operator',
                'name' => '运营管理员 (Operator)',
                'description' => '负责日常通道配置、轮询组管理、商户审核及交易监控，无法操作子账号。',
                'is_system' => false,
            ],
            [
                'role' => 'finance',
                'name' => '财务管理员 (Finance)',
                'description' => '负责订单核销、充值记录、资金流水、财务报表与补单操作。',
                'is_system' => false,
            ],
            [
                'role' => 'support',
                'name' => '客服支持 (Support)',
                'description' => '只读权限，负责订单与商户基础资料查询，无法修改任何配置。',
                'is_system' => false,
            ],
        ];

        return json_encode([
            'code' => 1,
            'data' => [
                'roles' => $definedRoles,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── 查询指定角色权限白名单 ───────────────────────────────────────────────
    public function permissions(Request $request): string
    {
        $role = trim((string)$request->get('role', ''));
        $query = DB::table('cx_admin_permission');
        if ($role !== '') {
            $query->where('role', $role);
        }
        $rows = $query->get();

        return json_encode([
            'code' => 1,
            'data' => [
                'permissions' => $rows->map(fn($r) => (array)$r)->values(),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── 自定义/更新角色权限 ───────────────────────────────────────────────────
    public function updatePermissions(Request $request): string
    {
        if ($err = $this->requireRoot($request)) return $err;

        $role = trim((string)$request->post('role', ''));
        $perms = $request->post('permissions', []);

        if ($role === '' || !in_array($role, ['operator', 'finance', 'support'], true)) {
            return json_encode(['code' => -1, 'msg' => '无效的角色标识'], JSON_UNESCAPED_UNICODE);
        }
        if (!is_array($perms)) {
            return json_encode(['code' => -1, 'msg' => '权限列表格式错误'], JSON_UNESCAPED_UNICODE);
        }

        DB::beginTransaction();
        try {
            DB::table('cx_admin_permission')->where('role', $role)->delete();
            $now = time();
            foreach ($perms as $p) {
                $prefix = trim((string)($p['path_prefix'] ?? ''));
                $method = strtoupper(trim((string)($p['method'] ?? '*')));
                $desc = trim((string)($p['description'] ?? ''));
                if ($prefix !== '') {
                    DB::table('cx_admin_permission')->insert([
                        'role' => $role,
                        'path_prefix' => $prefix,
                        'method' => $method,
                        'description' => $desc,
                        'create_time' => $now,
                    ]);
                }
            }
            DB::commit();

            // 清理权限缓存
            try {
                \Webman\Redis\Client::connection()->del('cx:admin_perm:' . $role);
            } catch (\Throwable) {}

            AuditLog::record(AuditLog::currentOperator(), 'update_role_permission', ['role' => $role, 'count' => count($perms)], 'success', AuditLog::currentIp());

            return json_encode(['code' => 1, 'msg' => "角色【{$role}】权限已成功更新并生效"], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            DB::rollBack();
            return json_encode(['code' => -1, 'msg' => '更新角色权限失败：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    // ── 新建 / 更新 ───────────────────────────────────────────────────────────
    public function save(Request $request): string
    {
        if ($err = $this->requireRoot($request)) return $err;

        $params      = $request->post();
        $id          = (int)($params['id'] ?? 0);
        $username    = trim((string)($params['username'] ?? ''));
        $role        = trim((string)($params['role'] ?? 'operator'));
        $displayName = trim((string)($params['display_name'] ?? ''));
        $password    = trim((string)($params['password'] ?? ''));

        if ($username === '') {
            return json_encode(['code' => -1, 'msg' => '账号不能为空'], JSON_UNESCAPED_UNICODE);
        }
        if (!in_array($role, ['operator', 'finance', 'support'], true)) {
            return json_encode(['code' => -1, 'msg' => '角色无效，可选：operator / finance / support'], JSON_UNESCAPED_UNICODE);
        }

        if ($id > 0) {
            // 更新
            $data = ['role' => $role, 'display_name' => $displayName];
            if ($password !== '') {
                if (strlen($password) < 6) {
                    return json_encode(['code' => -1, 'msg' => '密码长度至少 6 位'], JSON_UNESCAPED_UNICODE);
                }
                $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            }
            DB::table('cx_admin')->where('id', $id)->update($data);
            AuditLog::record(AuditLog::currentOperator(), 'update_sub_admin', ['id' => $id, 'username' => $username], 'success', AuditLog::currentIp());
            return json_encode(['code' => 1, 'msg' => '子管理员已更新'], JSON_UNESCAPED_UNICODE);
        }

        // 新建
        if ($password === '' || strlen($password) < 6) {
            return json_encode(['code' => -1, 'msg' => '新建账号时密码不能为空且至少 6 位'], JSON_UNESCAPED_UNICODE);
        }
        $exists = DB::table('cx_admin')->where('username', $username)->exists();
        if ($exists) {
            return json_encode(['code' => -1, 'msg' => '账号已存在'], JSON_UNESCAPED_UNICODE);
        }
        DB::table('cx_admin')->insert([
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'role'          => $role,
            'display_name'  => $displayName,
            'status'        => 1,
            'create_time'   => time(),
        ]);
        AuditLog::record(AuditLog::currentOperator(), 'create_sub_admin', ['username' => $username, 'role' => $role], 'success', AuditLog::currentIp());
        return json_encode(['code' => 1, 'msg' => '子管理员已创建'], JSON_UNESCAPED_UNICODE);
    }

    // ── 删除 ──────────────────────────────────────────────────────────────────
    public function delete(Request $request): string
    {
        if ($err = $this->requireRoot($request)) return $err;

        $id = (int)$request->post('id', 0);
        if ($id <= 0) {
            return json_encode(['code' => -1, 'msg' => '参数错误'], JSON_UNESCAPED_UNICODE);
        }
        $admin = DB::table('cx_admin')->where('id', $id)->first();
        if (!$admin) {
            return json_encode(['code' => -1, 'msg' => '账号不存在'], JSON_UNESCAPED_UNICODE);
        }
        DB::table('cx_admin')->where('id', $id)->delete();
        AuditLog::record(AuditLog::currentOperator(), 'delete_sub_admin', ['id' => $id, 'username' => $admin->username], 'success', AuditLog::currentIp());
        return json_encode(['code' => 1, 'msg' => '已删除'], JSON_UNESCAPED_UNICODE);
    }

    // ── 启用 / 禁用 ───────────────────────────────────────────────────────────
    public function toggle(Request $request): string
    {
        if ($err = $this->requireRoot($request)) return $err;

        $id     = (int)$request->post('id', 0);
        $status = (int)$request->post('status', 0) === 1 ? 1 : 0;
        if ($id <= 0) {
            return json_encode(['code' => -1, 'msg' => '参数错误'], JSON_UNESCAPED_UNICODE);
        }
        DB::table('cx_admin')->where('id', $id)->update(['status' => $status]);
        AuditLog::record(AuditLog::currentOperator(), 'toggle_sub_admin', ['id' => $id, 'status' => $status], 'success', AuditLog::currentIp());
        return json_encode(['code' => 1, 'msg' => $status === 1 ? '已启用' : '已禁用'], JSON_UNESCAPED_UNICODE);
    }

    // ── 生成邀请链接 ──────────────────────────────────────────────────────────
    /**
     * 生成一次性邀请链接，被邀请者点击后可自行设置密码并激活账号。
     * Token 写入 Redis Key: cx:admin_invite:{token}，TTL 86400s（24小时）。
     * 被邀请者通过公开端点 POST /api/admin/sub/activate 消费此 token 完成注册。
     */
    public function invite(Request $request): string
    {
        if ($err = $this->requireRoot($request)) return $err;

        $username    = trim((string)$request->post('username', ''));
        $role        = trim((string)$request->post('role', 'operator'));
        $displayName = trim((string)$request->post('display_name', ''));

        if ($username === '') {
            return json_encode(['code' => -1, 'msg' => '请填写被邀请账号名'], JSON_UNESCAPED_UNICODE);
        }
        if (!in_array($role, ['operator', 'finance', 'support'], true)) {
            return json_encode(['code' => -1, 'msg' => '角色无效'], JSON_UNESCAPED_UNICODE);
        }
        if (DB::table('cx_admin')->where('username', $username)->exists()) {
            return json_encode(['code' => -1, 'msg' => '账号已存在，无需邀请'], JSON_UNESCAPED_UNICODE);
        }

        $token    = bin2hex(random_bytes(32)); // 64 字符随机令牌
        $ttl      = 86400;                     // 24 小时有效
        $redisKey = 'cx:admin_invite:' . hash('sha256', $token);

        $meta = json_encode([
            'username'     => $username,
            'role'         => $role,
            'display_name' => $displayName,
            'invited_by'   => AuditLog::currentOperator(),
            'invited_at'   => time(),
        ], JSON_UNESCAPED_UNICODE);

        try {
            \Webman\Redis\Client::connection()->setex($redisKey, $ttl, $meta);
        } catch (\Throwable) {
            return json_encode(['code' => -1, 'msg' => 'Redis 异常，邀请链接生成失败'], JSON_UNESCAPED_UNICODE);
        }

        // 邀请激活端点（前端拼接后发给被邀请者）
        $baseUrl    = rtrim((string)config('app.url', ''), '/');
        $activateUrl = $baseUrl . '/admin_login.html?invite=' . $token;

        AuditLog::record(AuditLog::currentOperator(), 'invite_sub_admin', ['username' => $username, 'role' => $role], 'success', AuditLog::currentIp());

        return json_encode([
            'code' => 1,
            'msg'  => '邀请链接已生成，有效期 24 小时',
            'data' => [
                'invite_token' => $token,
                'activate_url' => $activateUrl,
                'expires_in'   => $ttl,
                'meta'         => ['username' => $username, 'role' => $role],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── 子管理员独立登录 ──────────────────────────────────────────────────────
    public function login(Request $request): string
    {
        $username = trim((string)$request->post('username', ''));
        $password = trim((string)$request->post('password', ''));

        if ($username === '' || $password === '') {
            return json_encode(['code' => -1, 'msg' => '账号与密码不能为空'], JSON_UNESCAPED_UNICODE);
        }

        $rateLimitId = $request->getRemoteIp() . '|sub|' . strtolower($username);
        if (\support\LoginRateLimiter::tooManyAttempts('sub_admin', $rateLimitId, 5, 300)) {
            return json_encode(['code' => -1, 'msg' => '登录失败次数过多，请5分钟后重试'], JSON_UNESCAPED_UNICODE);
        }

        $admin = DB::table('cx_admin')->where('username', $username)->first();
        if (!$admin || (int)$admin->status !== 1) {
            \support\LoginRateLimiter::increment('sub_admin', $rateLimitId);
            return json_encode(['code' => -1, 'msg' => '账号不存在或已被禁用'], JSON_UNESCAPED_UNICODE);
        }
        if (!password_verify($password, (string)$admin->password_hash)) {
            \support\LoginRateLimiter::increment('sub_admin', $rateLimitId);
            return json_encode(['code' => -1, 'msg' => '账号或密码错误'], JSON_UNESCAPED_UNICODE);
        }

        \support\LoginRateLimiter::clear('sub_admin', $rateLimitId);
        DB::table('cx_admin')->where('id', $admin->id)->update([
            'last_login_at' => time(),
            'last_login_ip' => $request->getRemoteIp(),
        ]);

        $tokenSalt = (string)DB::table('cx_config')->where('name', 'token_salt')->value('value');
        if (strlen($tokenSalt) < 32) {
            return json_encode(['code' => -1, 'msg' => '系统 Token 盐未安全初始化'], JSON_UNESCAPED_UNICODE);
        }
        $tokenVersion = (int)(DB::table('cx_config')->where('name', 'admin_token_version')->value('value') ?: 1);
        $tokenExpire  = time() + 7200;
        $tokenRaw     = 'sub:' . $username . '|' . $tokenExpire . '|v' . $tokenVersion;
        $token        = base64_encode($tokenRaw . '|' . hash_hmac('sha256', $tokenRaw, $tokenSalt));

        $request->session()->set('admin_info', [
            'username'     => $username,
            'login_time'   => time(),
            'token_expire' => $tokenExpire,
            'token_version'=> $tokenVersion,
            'role'         => (string)$admin->role,
        ]);
        $request->sessionRegenerateId(true);

        AuditLog::record($username, 'sub_admin_login', ['ip' => $request->getRemoteIp()], 'success', $request->getRemoteIp());

        return json_encode([
            'code' => 1, 'msg' => '登录成功',
            'data' => ['token' => $token, 'username' => $username, 'role' => $admin->role, 'expire' => $tokenExpire],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ── 邀请激活：被邀请者设置密码完成注册 ───────────────────────────────────
    public function activate(Request $request): string
    {
        $inviteToken     = trim((string)$request->post('invite_token', ''));
        $password        = trim((string)$request->post('password', ''));
        $passwordConfirm = trim((string)$request->post('password_confirm', ''));

        if ($inviteToken === '' || $password === '') {
            return json_encode(['code' => -1, 'msg' => '参数不完整'], JSON_UNESCAPED_UNICODE);
        }
        if ($password !== $passwordConfirm) {
            return json_encode(['code' => -1, 'msg' => '两次密码不一致'], JSON_UNESCAPED_UNICODE);
        }
        if (strlen($password) < 6) {
            return json_encode(['code' => -1, 'msg' => '密码长度至少 6 位'], JSON_UNESCAPED_UNICODE);
        }

        $redisKey = 'cx:admin_invite:' . hash('sha256', $inviteToken);
        try {
            $redis = \Webman\Redis\Client::connection();
            $meta  = $redis->get($redisKey);
            if (!$meta) {
                return json_encode(['code' => -1, 'msg' => '邀请链接已过期或已被使用'], JSON_UNESCAPED_UNICODE);
            }
            $info = json_decode((string)$meta, true);
        } catch (\Throwable) {
            return json_encode(['code' => -1, 'msg' => '服务异常，请稍后重试'], JSON_UNESCAPED_UNICODE);
        }

        $uname = (string)($info['username'] ?? '');
        $role  = (string)($info['role'] ?? 'operator');
        if ($uname === '') {
            return json_encode(['code' => -1, 'msg' => '邀请信息异常'], JSON_UNESCAPED_UNICODE);
        }
        if (DB::table('cx_admin')->where('username', $uname)->exists()) {
            return json_encode(['code' => -1, 'msg' => '账号已存在，无需重复激活'], JSON_UNESCAPED_UNICODE);
        }

        DB::table('cx_admin')->insert([
            'username'      => $uname,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'role'          => $role,
            'display_name'  => (string)($info['display_name'] ?? ''),
            'status'        => 1,
            'create_time'   => time(),
        ]);

        try { \Webman\Redis\Client::connection()->del($redisKey); } catch (\Throwable) {}

        AuditLog::record($uname, 'sub_admin_activate', ['role' => $role], 'success', $request->getRemoteIp());

        return json_encode(['code' => 1, 'msg' => '账号已激活，请使用新密码登录'], JSON_UNESCAPED_UNICODE);
    }
}
