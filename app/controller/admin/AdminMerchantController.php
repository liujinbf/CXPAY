<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\model\Merchant;
use app\model\Order;
use app\model\Channel;
use app\model\Plan;
use app\model\UserMoneyLog;
use Illuminate\Database\Capsule\Manager as DB;
use support\AuditLog;
use support\IpWhitelist;

/**
 * 管理员商户管理控制器（查询、开户、编辑套餐/密钥/配额、余额调整、安全删除与批量清理）
 */
final class AdminMerchantController
{
    /**
     * 分页获取商户列表（带套餐名称、关联通道与订单统计）
     */
    public function listMerchants(\support\Request $request): string
    {
        $keyword  = trim((string)$request->get('keyword', ''));
        $pageSize = max(1, min(100, (int)$request->get('page_size', 20)));
        if (mb_strlen($keyword) > 100) {
            return json_encode(['code' => -1, 'msg' => '搜索关键词过长'], JSON_UNESCAPED_UNICODE);
        }

        $query = Merchant::query();
        if ($keyword !== '') {
            $escaped = addcslashes($keyword, '%_\\');
            $query->where(function ($builder) use ($escaped): void {
                $builder->where('pid', 'like', "%{$escaped}%")
                    ->orWhere('name', 'like', "%{$escaped}%");
            });
        }

        $paginator = $query->orderByDesc('id')->paginate($pageSize);

        // 预加载所有套餐信息
        $plans = Plan::all()->keyBy('id');

        // 统计当前批次商户的通道数与订单数
        $merchantIds = collect($paginator->items())->pluck('id')->toArray();
        $channelCounts = Channel::whereIn('merchant_id', $merchantIds)
            ->groupBy('merchant_id')
            ->selectRaw('merchant_id, count(*) as cnt')
            ->pluck('cnt', 'merchant_id')
            ->toArray();

        $orderCounts = Order::whereIn('merchant_id', $merchantIds)
            ->groupBy('merchant_id')
            ->selectRaw('merchant_id, count(*) as cnt')
            ->pluck('cnt', 'merchant_id')
            ->toArray();

        $now = time();
        $list = collect($paginator->items())->map(function ($m) use ($plans, $channelCounts, $orderCounts, $now) {
            $plan = $plans->get($m->plan_id);
            $isVip = ((int)$m->plan_id > 1 && ((int)$m->plan_expire_time === 0 || (int)$m->plan_expire_time > $now))
                  || ((int)$m->packvip_id > 0 && ((int)$m->packvip_time === 0 || (int)$m->packvip_time > $now));

            $planName = $plan ? (string)$plan->name : '默认基础套餐';
            if ($isVip) {
                $planBadge = $planName;
            } elseif ((int)$m->plan_id === 1) {
                $planBadge = '体验套餐';
            } else {
                $planBadge = '无套餐';
            }

            return [
                'id'                 => (int)$m->id,
                'pid'                => (string)$m->pid,
                'name'               => (string)$m->name,
                'money'              => number_format((float)($m->money ?? 0), 2, '.', ''),
                'rate'               => (float)($m->rate ?? 0.02),
                'rate_percent'       => number_format((float)($m->rate ?? 0.02) * 100, 2, '.', '') . '%',
                'plan_id'            => (int)($m->plan_id ?? 0),
                'plan_name'          => $planBadge,
                'is_vip'             => $isVip,
                'plan_expire_time'   => (int)($m->plan_expire_time ?? 0),
                'plan_expire_format' => (int)$m->plan_expire_time > 0 ? date('Y-m-d', (int)$m->plan_expire_time) : '永久有效',
                'channel_quota'      => (int)($m->channel_quota ?? 0),
                'channel_count'      => (int)($channelCounts[$m->id] ?? 0),
                'order_count'        => (int)($orderCounts[$m->id] ?? 0),
                'status'             => (int)$m->status,
                'ip_white'           => (string)($m->ip_white ?? ''),
                'create_time'        => !empty($m->create_time) ? date('Y-m-d H:i:s', (int)$m->create_time) : '--',
            ];
        });

        return json_encode([
            'code' => 1,
            'data' => [
                'list'  => $list,
                'total' => $paginator->total(),
                'page'  => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 获取指定商户完整详情（包含 API 密钥明文以供管理维护）
     */
    public function getMerchantDetail(\support\Request $request): string
    {
        $id = (int)$request->get('id', 0);
        $merchant = Merchant::find($id);
        if (!$merchant) {
            return json_encode(['code' => -1, 'msg' => '商户不存在'], JSON_UNESCAPED_UNICODE);
        }

        $plan = Plan::find((int)$merchant->plan_id);
        $allPlans = Plan::where('status', 1)->orderBy('sort_order')->get()->map(function ($p) {
            return ['id' => $p->id, 'name' => $p->name, 'price' => $p->price, 'days' => $p->days];
        });

        return json_encode([
            'code' => 1,
            'data' => [
                'id'                 => (int)$merchant->id,
                'pid'                => (string)$merchant->pid,
                'name'               => (string)$merchant->name,
                'key'                => (string)$merchant->key,
                'rate'               => (float)($merchant->rate ?? 0.02),
                'money'              => number_format((float)($merchant->money ?? 0), 2, '.', ''),
                'plan_id'            => (int)($merchant->plan_id ?? 0),
                'plan_expire_time'   => (int)($merchant->plan_expire_time ?? 0),
                'plan_expire_date'   => (int)$merchant->plan_expire_time > 0 ? date('Y-m-d', (int)$merchant->plan_expire_time) : '',
                'channel_quota'      => (int)($merchant->channel_quota ?? 0),
                'status'             => (int)$merchant->status,
                'ip_white'           => (string)($merchant->ip_white ?? ''),
                'plans'              => $allPlans,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 新增 / 更新商户账号、套餐、配额与密钥
     */
    public function saveMerchant(\support\Request $request): string
    {
        $params = $request->post();
        $id     = (int)($params['id'] ?? 0);

        $name = trim((string)($params['name'] ?? '新商户'));
        $submittedKey = trim((string)($params['key'] ?? ''));
        $loginPassword = (string)($params['login_password'] ?? '');
        $rate = (float)($params['rate'] ?? 0.02);
        $planId = (int)($params['plan_id'] ?? 0);
        $channelQuota = max(0, (int)($params['channel_quota'] ?? 0));

        if ($name === '' || mb_strlen($name) > 100 || $rate < 0 || $rate > 1) {
            return json_encode(['code' => -1, 'msg' => '商户名称或费率格式不合法（费率需在0～1之间）'], JSON_UNESCAPED_UNICODE);
        }
        if ($submittedKey !== '' && (strlen($submittedKey) < 16 || strlen($submittedKey) > 64)) {
            return json_encode(['code' => -1, 'msg' => 'API 密钥长度需在16至64个字符之间'], JSON_UNESCAPED_UNICODE);
        }
        if ($loginPassword !== '' && (strlen($loginPassword) < 6 || strlen($loginPassword) > 200)) {
            return json_encode(['code' => -1, 'msg' => '商户登录密码长度至少为6个字符'], JSON_UNESCAPED_UNICODE);
        }
        $ipWhitelist = IpWhitelist::normalize((string)($params['ip_white'] ?? ''));
        if ($ipWhitelist === null) {
            return json_encode(['code' => -1, 'msg' => 'IP 白名单格式不合法'], JSON_UNESCAPED_UNICODE);
        }

        // 套餐到期时间处理
        $expireDate = trim((string)($params['plan_expire_date'] ?? ''));
        $expireTime = 0;
        if ($expireDate !== '') {
            $ts = strtotime($expireDate . ' 23:59:59');
            if ($ts !== false) {
                $expireTime = $ts;
            }
        }

        $merchantData = [
            'name'             => $name,
            'rate'             => $rate,
            'ip_white'         => $ipWhitelist,
            'status'           => (int)($params['status'] ?? 1) === 1 ? 1 : 0,
            'plan_id'          => $planId,
            'plan_expire_time' => $expireTime,
            'channel_quota'    => $channelQuota,
        ];

        if ($id > 0) {
            $merchant = Merchant::find($id);
            if (!$merchant) {
                return json_encode(['code' => -1, 'msg' => '商户不存在'], JSON_UNESCAPED_UNICODE);
            }
            if ($submittedKey !== '') {
                $merchantData['key'] = $submittedKey;
            }
            if ($loginPassword !== '') {
                $merchantData['password_hash'] = password_hash($loginPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            }
            $merchant->fill($merchantData);
            $merchant->save();
            $msg = '商户信息更新成功';
            $initialPassword = null;
        } else {
            $pid = trim((string)($params['pid'] ?? ''));
            if ($pid === '') {
                $lastId = (int)Merchant::max('id') ?: 0;
                $pid = (string)(1000 + $lastId + 1);
                while (Merchant::where('pid', $pid)->exists()) {
                    $pid = (string)((int)$pid + 1);
                }
            }
            if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $pid) || Merchant::where('pid', $pid)->exists()) {
                return json_encode(['code' => -1, 'msg' => '商户 PID 格式不合法或已存在'], JSON_UNESCAPED_UNICODE);
            }
            $key = $submittedKey !== '' ? $submittedKey : bin2hex(random_bytes(16));
            $merchantData['pid'] = $pid;
            $merchantData['key'] = $key;
            $merchantData['money'] = 0.00;
            $merchantData['create_time'] = time();
            $initialPassword = $loginPassword !== ''
                ? $loginPassword
                : rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
            $merchantData['password_hash'] = password_hash($initialPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $merchant = Merchant::create($merchantData);
            $msg = '新商户开户成功';
        }

        // 清除 Redis 密钥与仪表盘缓存
        try {
            $redis = \Webman\Redis\Client::connection();
            $redis->del('cx:merchant_key:' . $merchant->pid);
            $redis->del('cx:dashboard_stats');
        } catch (\Throwable) {}

        return json_encode([
            'code' => 1,
            'msg' => $msg,
            'data' => [
                'pid' => (string)$merchant->pid,
                'api_key' => (string)$merchant->key,
                'initial_password' => $initialPassword,
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 删除指定商户
     */
    public function deleteMerchant(\support\Request $request): string
    {
        $id = (int)$request->post('id', 0);
        $merchant = Merchant::find($id);
        if (!$merchant) {
            return json_encode(['code' => -1, 'msg' => '商户不存在'], JSON_UNESCAPED_UNICODE);
        }

        // 禁止删除系统核心主商户
        if ($merchant->pid === '1000' || (int)$merchant->id === 1000) {
            return json_encode(['code' => -1, 'msg' => '系统主商户（PID 1000）受到安全保护，禁止删除！'], JSON_UNESCAPED_UNICODE);
        }

        try {
            DB::connection()->transaction(function () use ($merchant) {
                // 清理商户关联的通道、资金日志与商户账号
                Channel::where('merchant_id', $merchant->id)->delete();
                UserMoneyLog::where('merchant_id', $merchant->id)->delete();
                $merchant->delete();
            });

            try {
                $redis = \Webman\Redis\Client::connection();
                $redis->del('cx:merchant_key:' . $merchant->pid);
                $redis->del('cx:dashboard_stats');
            } catch (\Throwable) {}

            return json_encode(['code' => 1, 'msg' => "商户 [{$merchant->name}]（PID: {$merchant->pid}）已安全删除"]);
        } catch (\Throwable $e) {
            return json_encode(['code' => -1, 'msg' => '删除失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 一键批量清理无用测试商户（0 订单 0 通道，保留主商户 1000）
     */
    public function batchCleanTestMerchants(\support\Request $request): string
    {
        try {
            $allMerchants = Merchant::where('pid', '!=', '1000')->where('id', '!=', 1000)->get();
            $deletedCount = 0;
            $deletedPids = [];

            foreach ($allMerchants as $m) {
                $hasOrders = Order::where('merchant_id', $m->id)->exists();
                $hasChannels = Channel::where('merchant_id', $m->id)->exists();

                // 没有任何订单且没有配置通道的测试账号允许清理
                if (!$hasOrders && !$hasChannels) {
                    $deletedPids[] = $m->pid;
                    UserMoneyLog::where('merchant_id', $m->id)->delete();
                    $m->delete();
                    $deletedCount++;
                }
            }

            try {
                $redis = \Webman\Redis\Client::connection();
                $redis->del('cx:dashboard_stats');
            } catch (\Throwable) {}

            return json_encode([
                'code' => 1,
                'msg'  => "已成功清理 {$deletedCount} 个闲置测试商户（" . implode(', ', $deletedPids) . "）",
                'data' => [
                    'cleaned_count' => $deletedCount,
                    'cleaned_pids'  => $deletedPids,
                ],
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['code' => -1, 'msg' => '清理测试商户失败: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * 管理员调整商户账户余额（充值 / 扣减）
     */
    public function adjustBalance(\support\Request $request): string
    {
        $id     = (int)($request->post('id') ?? 0);
        $amount = (float)($request->post('amount') ?? 0);
        $type   = trim((string)($request->post('type') ?? 'add'));
        $memo   = mb_substr(trim((string)($request->post('memo') ?? '')), 0, 200);

        if ($id <= 0) {
            return json_encode(['code' => -1, 'msg' => '商户 ID 不合法'], JSON_UNESCAPED_UNICODE);
        }
        if (!in_array($type, ['add', 'sub'], true)) {
            return json_encode(['code' => -1, 'msg' => '调整类型只允许 add（充值）或 sub（扣减）'], JSON_UNESCAPED_UNICODE);
        }
        if ($amount <= 0 || $amount > 999999.99) {
            return json_encode(['code' => -1, 'msg' => '调整金额必须在 0.01 ~ 999999.99 范围内'], JSON_UNESCAPED_UNICODE);
        }
        if ($memo === '') {
            return json_encode(['code' => -1, 'msg' => '请填写调整备注，便于审计追溯'], JSON_UNESCAPED_UNICODE);
        }

        try {
            $result = DB::connection()->transaction(function () use ($id, $amount, $type, $memo, $request): array {
                $merchant = Merchant::where('id', $id)->lockForUpdate()->first();
                if (!$merchant) {
                    throw new \InvalidArgumentException('商户不存在');
                }

                $before = (string)$merchant->money;
                if ($type === 'add') {
                    $merchant->money = (string)round((float)$merchant->money + $amount, 4);
                } else {
                    if ((float)$merchant->money < $amount) {
                        throw new \InvalidArgumentException('商户余额不足，无法扣减');
                    }
                    $merchant->money = (string)round((float)$merchant->money - $amount, 4);
                }
                $merchant->save();

                $operator  = AuditLog::currentOperator();
                $adjustStr = ($type === 'add' ? '+' : '-') . number_format($amount, 2, '.', '');
                UserMoneyLog::log(
                    (int)$merchant->id,
                    $adjustStr,
                    $before,
                    (string)$merchant->money,
                    '[管理员调整] ' . $memo . ' — by ' . $operator
                );

                AuditLog::record(
                    $operator,
                    'admin_adjust_balance',
                    ['merchant_id' => $id, 'type' => $type, 'amount' => $amount, 'before' => $before, 'after' => $merchant->money, 'memo' => $memo],
                    'success',
                    AuditLog::currentIp()
                );

                return ['before' => $before, 'after' => (string)$merchant->money];
            });

            return json_encode([
                'code' => 1,
                'msg'  => '余额调整成功',
                'data' => $result,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\InvalidArgumentException $e) {
            return json_encode(['code' => -1, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return json_encode(['code' => -1, 'msg' => '余额调整失败：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}
