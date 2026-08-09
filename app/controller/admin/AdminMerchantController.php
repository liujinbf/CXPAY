<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Merchant;
use support\IpWhitelist;

/**
 * 管理员商户查询与开户配置控制器
 */
final class AdminMerchantController
{
    /**
     * 分页获取商户列表，敏感登录哈希与 API 密钥永不下发。
     */
    public function listMerchants(\support\Request $request): string
    {
        $keyword = trim((string)$request->get('keyword', ''));
        $pageSize = max(1, min(100, (int)$request->get('page_size', 20)));
        if (mb_strlen($keyword) > 100) {
            return json_encode(['code' => -1, 'msg' => '搜索关键词过长'], JSON_UNESCAPED_UNICODE);
        }

        $query = Merchant::query()->select([
            'id', 'pid', 'name', 'money', 'rate', 'packvip_id', 'packvip_time',
            'ip_white', 'status', 'create_time',
        ]);
        if ($keyword !== '') {
            $escaped = addcslashes($keyword, '%_\\');
            $query->where(function ($builder) use ($escaped): void {
                $builder->where('pid', 'like', "%{$escaped}%")
                    ->orWhere('name', 'like', "%{$escaped}%");
            });
        }

        return json_encode([
            'code' => 1,
            'data' => $query->orderByDesc('id')->paginate($pageSize),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 新增 / 更新商户账号与费率折扣
     */
    public function saveMerchant(\support\Request $request): string
    {
        $params = $request->post();
        $id     = (int)($params['id'] ?? 0);

        $name = trim((string)($params['name'] ?? '新商户'));
        $submittedKey = trim((string)($params['key'] ?? ''));
        $loginPassword = (string)($params['login_password'] ?? '');
        $rate = (float)($params['rate'] ?? 0.02);
        if ($name === '' || mb_strlen($name) > 100 || $rate < 0 || $rate > 1) {
            return json_encode(['code' => -1, 'msg' => '商户名称、密钥或费率格式不合法'], JSON_UNESCAPED_UNICODE);
        }
        if ($submittedKey !== '' && (strlen($submittedKey) < 32 || strlen($submittedKey) > 64)) {
            return json_encode(['code' => -1, 'msg' => 'API 密钥长度必须为32至64个字符'], JSON_UNESCAPED_UNICODE);
        }
        if ($loginPassword !== '' && (strlen($loginPassword) < 6 || strlen($loginPassword) > 200)) {
            return json_encode(['code' => -1, 'msg' => '商户登录密码长度至少为6个字符'], JSON_UNESCAPED_UNICODE);
        }
        $ipWhitelist = IpWhitelist::normalize((string)($params['ip_white'] ?? ''));
        if ($ipWhitelist === null) {
            return json_encode(['code' => -1, 'msg' => 'IP 白名单格式不合法，仅支持最多50个 IPv4/IPv6 地址'], JSON_UNESCAPED_UNICODE);
        }

        $merchantData = [
            'name'       => $name,
            'rate'       => $rate,
            'ip_white'   => $ipWhitelist,
            'status'     => (int)($params['status'] ?? 1) === 1 ? 1 : 0,
        ];

        if ($id > 0) {
            $merchant = Merchant::find($id);
            if (!$merchant) {
                return json_encode(['code' => -1, 'msg' => '商户不存在'], JSON_UNESCAPED_UNICODE);
            }
            // 编辑资料时不默认轮换 API 密钥；只有管理员明确提交新密钥才更新。
            if ($submittedKey !== '') {
                $merchantData['key'] = $submittedKey;
            }
            if ($loginPassword !== '') {
                $merchantData['password_hash'] = password_hash($loginPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            }
            $merchant->fill($merchantData);
            $merchant->save();
            $msg = '商户更新成功';
            $initialPassword = null;
        } else {
            $pid = trim((string)($params['pid'] ?? '')) ?: ('M' . strtoupper(bin2hex(random_bytes(6))));
            if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $pid) || Merchant::where('pid', $pid)->exists()) {
                return json_encode(['code' => -1, 'msg' => '商户 PID 格式不合法或已存在'], JSON_UNESCAPED_UNICODE);
            }
            $key = $submittedKey !== '' ? $submittedKey : bin2hex(random_bytes(24));
            $merchantData['pid'] = $pid;
            $merchantData['key'] = $key;
            $merchantData['money'] = 0.00;
            $merchantData['create_time'] = time();
            $initialPassword = $loginPassword !== ''
                ? $loginPassword
                : rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
            $merchantData['password_hash'] = password_hash($initialPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            Merchant::create($merchantData);
            $msg = '新商户开户成功';
        }

        return json_encode([
            'code' => 1,
            'msg' => $msg,
            'data' => [
                'pid' => $id ? (string)$merchant->pid : $pid,
                'api_key' => $id > 0 ? ($submittedKey !== '' ? $submittedKey : null) : $key,
                'initial_password' => $initialPassword,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}
