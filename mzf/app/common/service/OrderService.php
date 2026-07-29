<?php

namespace app\common\service;

use think\facade\Db;
use think\facade\Cache;
use think\facade\Config;
use app\admin\model\User;
use app\common\model\PayOrder;
use app\common\model\PayChannel;
use app\common\model\PayPackvip;
use app\common\model\PayPollGroup;
use app\common\model\PayPollGroupChannel;
use app\common\library\Sign;
use app\common\library\SnowFlake;
use app\common\library\payment\PaymentManager;

/**
 * 下单服务
 *
 * 逐条移植旧 SubmitController::actionIndex 的资金逻辑（红线，禁止随意改动）：
 *   验签(pay_key) → 会员/费率/余额校验 → 通道轮询选择(限额过滤) → 金额去重递增 → 建单
 *
 * 与旧系统差异（等价，非行为变更）：
 *   - 商户即会员：pid 就是 ba_user.id；签名密钥用 ba_user.pay_key(旧 user.key)。
 *   - 余额在 ba_user.money(分)，模型访问器已转「元」，故仍以元比较。
 *   - 时间字段 create_time 为时间戳；金额 decimal 字符串比较。
 *   - 并发：对 (pid,type) 加 Redis 锁串行化，避免并发下单重复占用 price / 轮询错乱。
 *
 * 失败一律抛 PayException（message 可直接展示给用户）。
 */
class OrderService
{
    /**
     * 创建订单
     *
     * @param array $params 下单参数（pid/type/out_trade_no/notify_url/return_url/name/money/param + sign...）
     * @return array{trade_no:string, price:string, money:string, channel:array, order_id:mixed}
     * @throws PayException
     */
    public function createOrder(array $params): array
    {
        $pid  = $params['pid'] ?? '';
        $type = $params['type'] ?? '';

        // 1) 商户
        if ($pid === '') {
            throw new PayException('你还未配置支付接口商户！');
        }
        // 商户：pid 为对接PID(随机10位)，据此定位会员；订单内部仍以 user.id 归属
        $user = User::where('pid', $pid)->find();
        if (!$user) {
            throw new PayException('商户不存在！');
        }
        if ($user->status !== 'enable') {
            throw new PayException('商户已被封禁，无法支付！');
        }

        // 2) 参数校验
        $out_trade_no = (string) ($params['out_trade_no'] ?? '');
        $notify_url   = (string) ($params['notify_url'] ?? '');
        $return_url   = (string) ($params['return_url'] ?? '');
        $name         = (string) ($params['name'] ?? '');
        $money        = (string) ($params['money'] ?? '');
        $param        = isset($params['param']) ? (string) $params['param'] : null;

        if ($out_trade_no === '') throw new PayException('订单号(out_trade_no)不能为空');
        if ($notify_url === '')   throw new PayException('通知地址(notify_url)不能为空');
        if ($return_url === '')   throw new PayException('回调地址(return_url)不能为空');
        if ($name === '')         throw new PayException('商品名称(name)不能为空');
        if ($money === '')        throw new PayException('金额(money)不能为空');
        if (!is_numeric($money) || $money <= 0 || !preg_match('/^[0-9.]+$/', $money)) {
            throw new PayException('金额不合法');
        }
        if (!preg_match('/^[a-zA-Z0-9.\_\-|]+$/', $out_trade_no)) {
            throw new PayException('订单号(out_trade_no)格式不正确');
        }

        // 3) 验签（商户 pay_key）
        if (!Sign::verifySign($params, $user->pay_key)) {
            throw new PayException('签名校验失败，请检查好对接密钥后返回重试！');
        }

        // 4) 会员有效期 + 余额校验
        if (!$user->packvip_time || (int) $user->packvip_time < time()) {
            throw new PayException('请先开通会员套餐再进行支付测试');
        }
        $packvip = PayPackvip::where('id', $user->packvip_id)->order('weigh desc')->find();
        if (!$packvip) {
            throw new PayException('套餐不存在');
        }
        if ($packvip->rate > 0) {
            $moneyRate = round((float) $money * (float) $packvip->rate / 100, 2);
            if ($moneyRate < 0.01 && $packvip->mini_rate > 0) {
                $moneyRate = (float) $packvip->mini_rate;
            }
            $feeCents = (int) round($moneyRate * 100);
            // 余额单位为分（模型获取器已转为元字符串），需转回分进行比较
            $userMoneyCents = (int) round((float) $user->money * 100);
            if ($userMoneyCents < $feeCents) {
                throw new PayException('商户余额不足无法发起支付，请先充值手续费（至少 ' . ($feeCents / 100) . ' 元）');
            }
        }
        $bindCtype = $packvip->bind_ctype ?: [];

        // 6) 通道轮询选择（加 Redis 锁串行化本商户+type 的下单）
        $lockKey = "lock:submit:{$user->id}:{$type}";
        $lock    = $this->acquireLock($lockKey, 5);
        try {
            $channel = $this->pickChannel((int) $user->id, $type, $bindCtype);
            if (!$channel) {
                throw new PayException('当前商户无通道可用，请去配置');
            }
            // 标记已轮询到
            PayChannel::where('id', $channel['id'])->update(['polling' => 1]);

            // 7) 重复订单
            $exist = PayOrder::where('out_trade_no', $out_trade_no)->find();
            if ($exist && (int) $exist->status === 1) {
                throw new PayException('此订单已支付成功');
            }

            // 8) 金额去重（可按商户配置区间随机上浮）
            $price = $this->resolvePrice($channel, $money, (float) $user->pay_float_min, (float) $user->pay_float_max);

            // 9) 建单
            $now     = time();
            $outtime = min(300, max(60, (int) $user->pay_outtime ?: 180)); // 60~300 秒
            $tradeNo = (string) SnowFlake::generateParticle();
            $order = new PayOrder();
            $order->save([
                'trade_no'     => $tradeNo,
                'out_trade_no' => $out_trade_no,
                'pid'          => $user->id,
                'type'         => $type,
                'channel_id'   => $channel['id'],
                'name'         => $name,
                'money'        => $money,
                'price'        => $price,
                'notify_url'   => $notify_url,
                'return_url'   => $return_url,
                'param'        => $param ?? '',
                'pay_id'       => request()->ip(),
                'status'       => 0,
                'config'       => '0',       // 通道监控方式
                'check_time'   => $now,      // 立即监控
                'expire_time'  => $now + $outtime, // 到期时间(=收银台倒计时/关单)
            ]);

            // 10) 驱动出码：填充收银台二维码（如固定收款码/联网出码）
            $this->fillPayQr($tradeNo, $channel, [
                'trade_no' => $tradeNo, 'out_trade_no' => $out_trade_no, 'price' => $price, 'money' => $money, 'name' => $name,
            ]);
        } finally {
            $this->releaseLock($lockKey, $lock);
        }

        return [
            'trade_no' => $tradeNo,
            'price'    => (string) $price,
            'money'    => $money,
            'channel'  => $channel,
            'order_id' => $tradeNo,
        ];
    }

    /**
     * 创建测试订单（商户中心自测）
     *
     * 用商户自己的 pid + 通道走真实下单链路（轮询选通道/金额去重/出码），
     * 但由内部发起、无需外部签名。付款后监控推送→结算，完整验证收款配置。
     *
     * @throws PayException
     */
    public function createTestOrder(int $uid, string $type, string $amount): array
    {
        return $this->createTestOrderWith($uid, $type, $amount, '');
    }

    /**
     * 创建测试订单（可带回调地址，便于验证商户回调）
     * @throws PayException
     */
    public function createTestOrderWith(int $uid, string $type, string $amount, string $notifyUrl = '', string $returnUrl = '', int $channelId = 0): array
    {
        $user = User::find($uid);
        if (!$user) {
            throw new PayException('商户不存在');
        }
        if (!$user->packvip_time || (int) $user->packvip_time < time()) {
            throw new PayException('请先开通会员套餐再进行支付测试');
        }
        $packvip = PayPackvip::where('id', $user->packvip_id)->order('weigh desc')->find();
        if (!$packvip) {
            throw new PayException('套餐不存在');
        }
        if (!is_numeric($amount) || $amount <= 0) {
            throw new PayException('测试金额不合法');
        }

        // 余额校验：手续费不足时禁止下单
        if ($packvip->rate > 0) {
            $moneyRate = round((float) $amount * (float) $packvip->rate / 100, 2);
            if ($moneyRate < 0.01 && $packvip->mini_rate > 0) {
                $moneyRate = (float) $packvip->mini_rate;
            }
            $feeCents = (int) round($moneyRate * 100);
            // 余额单位为分（模型获取器已转为元字符串），需转回分进行比较
            $userMoneyCents = (int) round((float) $user->money * 100);
            if ($userMoneyCents < $feeCents) {
                throw new PayException('商户余额不足无法发起支付，请先充值手续费（至少 ' . ($feeCents / 100) . ' 元）');
            }
        }
        $bindCtype = $packvip->bind_ctype ?: [];

        $lockKey = "lock:submit:{$uid}:{$type}";
        $lock    = $this->acquireLock($lockKey, 5);
        try {
            if ($channelId > 0) {
                // 指定通道测试：直接用该通道（限本商户+在线），便于单独验证某条通道
                $ch = PayChannel::where(['id' => $channelId, 'uid' => $uid, 'status' => 1, 'tt_switch' => 'true'])->find();
                if (!$ch) {
                    throw new PayException('指定通道不存在或不在线');
                }
                $channel = $ch->toArray();
            } else {
                $channel = $this->pickChannel($uid, $type, $bindCtype);
                if (!$channel) {
                    throw new PayException('无可用通道，请先配置并启用对应类型的通道');
                }
            }
            PayChannel::where('id', $channel['id'])->update(['polling' => 1]);

            $price   = $this->resolvePrice($channel, $amount);
            $tradeNo = (string) SnowFlake::generateParticle();
            $outNo   = 'TEST_' . date('YmdHis') . mt_rand(1000, 9999);

            (new PayOrder())->save([
                'trade_no'     => $tradeNo,
                'out_trade_no' => $outNo,
                'pid'          => $uid,
                'type'         => $type,
                'channel_id'   => $channel['id'],
                'name'         => '支付测试',
                'money'        => $amount,
                'price'        => $price,
                'notify_url'   => $notifyUrl,    // 测试单回调地址（系统自动分配）
                'return_url'   => $returnUrl,    // 支付成功跳转地址（收款通道页）
                'param'        => '',
                'pay_id'       => request()->ip(),
                'status'       => 0,
                'config'       => '0',
                'check_time'   => time(),
            ]);

            $this->fillPayQr($tradeNo, $channel, [
                'trade_no' => $tradeNo, 'out_trade_no' => $outNo, 'price' => $price, 'money' => $amount, 'name' => '支付测试',
            ]);
        } finally {
            $this->releaseLock($lockKey, $lock);
        }

        return [
            'trade_no' => $tradeNo,
            'price'    => (string) $price,
            'pay_url'  => '/gateway/Submit/pay?trade_no=' . $tradeNo,
        ];
    }

    /**
     * 创建在线充值订单（移植旧 RechargeController::actionSubmitRecharge）
     *
     * 商户($payerUid) 付款给平台收款账号(recharge_uid)，付款成功后在结算阶段给商户加余额。
     *   - 订单归属 pid=recharge_uid（走平台收款通道），param="recharge:{payerUid}" 标记充值。
     *   - 不带 notify_url（不外发商户回调），结算命中 param 前缀时改为给 payer 加余额、不扣费。
     * @throws PayException
     */
    public function createRechargeOrder(int $payerUid, string $type, string $amount): array
    {
        [$rechargeUid, $min, $max] = self::rechargeConfig();

        if ($rechargeUid <= 0) {
            throw new PayException('在线充值未开放，请联系管理员配置收款账号');
        }
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new PayException('充值金额不合法');
        }
        if ((float) $amount < $min) {
            throw new PayException('单笔充值不能小于 ' . $min . ' 元');
        }
        if ($max > 0 && (float) $amount > $max) {
            throw new PayException('单笔充值不能大于 ' . $max . ' 元');
        }
        if (!User::find($payerUid)) {
            throw new PayException('商户不存在');
        }

        $lockKey = "lock:recharge:{$rechargeUid}:{$type}";
        $lock    = $this->acquireLock($lockKey, 5);
        try {
            // 平台收款账号的通道不做套餐绑定过滤
            $channel = $this->pickChannel($rechargeUid, $type, [], true);
            if (!$channel) {
                throw new PayException('充值通道暂不可用，请稍后再试或更换支付方式');
            }
            PayChannel::where('id', $channel['id'])->update(['polling' => 1]);

            $price   = $this->resolvePrice($channel, $amount);
            $tradeNo = (string) SnowFlake::generateParticle();
            $outNo   = 'RC_' . date('YmdHis') . mt_rand(1000, 9999);

            (new PayOrder())->save([
                'trade_no'     => $tradeNo,
                'out_trade_no' => $outNo,
                'pid'          => $rechargeUid,
                'type'         => $type,
                'channel_id'   => $channel['id'],
                'name'         => '余额充值',
                'money'        => $amount,
                'price'        => $price,
                'notify_url'   => '',
                'return_url'   => '',
                'param'        => 'recharge:' . $payerUid,
                'pay_id'       => request()->ip(),
                'status'       => 0,
                'config'       => '0',
                'check_time'   => time(),
            ]);

            $this->fillPayQr($tradeNo, $channel, [
                'trade_no' => $tradeNo, 'out_trade_no' => $outNo, 'price' => $price, 'money' => $amount, 'name' => '余额充值',
            ]);
        } finally {
            $this->releaseLock($lockKey, $lock);
        }

        return [
            'trade_no' => $tradeNo,
            'price'    => (string) $price,
            'pay_url'  => '/gateway/Submit/pay?trade_no=' . $tradeNo,
        ];
    }

    /**
     * 在线充值配置：优先读后台系统配置(ba_config)，缺省回退 config/payment.php。
     * @return array{0:int,1:float,2:float} [收款商户ID, 单笔最小额, 单笔最大额(0=不限)]
     */
    public static function rechargeConfig(): array
    {
        $uid = get_sys_config('recharge_uid');
        $min = get_sys_config('recharge_min');
        $max = get_sys_config('recharge_max');
        $uidRaw = ($uid !== null && $uid !== '') ? $uid : Config::get('payment.recharge_uid', 0);
        return [
            self::resolveRechargeUid((string) $uidRaw),
            (float) ($min !== null && $min !== '' ? $min : Config::get('payment.recharge_min', 1)),
            (float) ($max !== null && $max !== '' ? $max : Config::get('payment.recharge_max', 10000)),
        ];
    }

    /**
     * 收款商户ID解析：支持填「商户号(M开头,外部pid)」或「内部user.id」，统一返回内部 user.id。
     */
    protected static function resolveRechargeUid(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
        if (ctype_digit($val)) {
            return (int) $val; // 纯数字=内部 user.id（兼容旧配置）
        }
        return (int) (User::where('pid', $val)->value('id') ?: 0); // M开头商户号 → user.id
    }

    /**
     * 轮询选择可用通道（移植旧逻辑：过滤 bind_ctype 与总/今日限额；全轮询过则重置重轮一次）
     */
    protected function pickChannel(int $uid, string $type, array $bindCtype, bool $ignoreBind = false): ?array
    {
        // 1) 有启用的轮询规则 → 规则内按模式(随机/权重轮询/权重优先)选通道
        $group = $this->resolveActiveGroup($uid, $type);
        if ($group) {
            $ch = $this->pickFromGroup($group, $uid, $type, $bindCtype, $ignoreBind);
            if ($ch) {
                return $ch;
            }
            // 规则内无可用候选 → 回退全部通道兜底（不断收）
        }

        // 2) 无规则 / 规则内无候选 → 现有 polling 轮转（全部该类型在线通道）
        $selected = $this->scanChannels($uid, $type, $bindCtype, $ignoreBind);
        if ($selected) {
            return $selected;
        }
        // 全部轮询过 → 清空 polling 重轮一次
        PayChannel::where(['uid' => $uid, 'type' => $type, 'status' => 1, 'tt_switch' => 'true'])->update(['polling' => 0]);
        return $this->scanChannels($uid, $type, $bindCtype, $ignoreBind);
    }

    /**
     * 取该支付方式当前启用的轮询规则（多启用时 weigh desc, id asc 择优唯一）。
     */
    protected function resolveActiveGroup(int $uid, string $type): ?array
    {
        $g = PayPollGroup::where(['uid' => $uid, 'type' => $type, 'status' => 1])
            ->order('weigh desc, id asc')->find();
        return $g ? $g->toArray() : null;
    }

    /**
     * 在规则内选通道：候选=规则内 且 (启用/开关开/未达限额/绑定通过)，按模式选。
     */
    protected function pickFromGroup(array $group, int $uid, string $type, array $bindCtype, bool $ignoreBind): ?array
    {
        $rows = PayPollGroupChannel::where('group_id', (int) $group['id'])->select()->toArray();
        if (!$rows) {
            return null;
        }
        $weightMap = [];
        $ids       = [];
        foreach ($rows as $r) {
            $cid = (int) $r['channel_id'];
            $ids[] = $cid;
            $weightMap[$cid] = max(1, (int) $r['weight']);
        }

        $chs = PayChannel::whereIn('id', $ids)
            ->where(['uid' => $uid, 'type' => $type, 'status' => 1, 'tt_switch' => 'true'])
            ->select();

        $todayStart = strtotime(date('Y-m-d'));
        $eligible   = [];
        foreach ($chs as $v) {
            // 云端授权门禁：被云端精准禁用的通道跳过
            if (!\app\core\CloudGuard::isChannelAllowed((string) $v['c_type'])) {
                continue;
            }
            // 套餐绑定过滤（充值 ignoreBind 时跳过）
            if (!$ignoreBind && !in_array($v['c_type'], $bindCtype, true) && !$this->ctypeBound($v['c_type'], $bindCtype)) {
                continue;
            }
            if ($this->channelLimitReached($v, $todayStart)) {
                continue;
            }
            $arr = $v->toArray();
            $arr['_weight'] = $weightMap[(int) $v['id']] ?? 1;
            $eligible[] = $arr;
        }
        if (!$eligible) {
            return null;
        }
        return $this->selectByMode($eligible, (string) $group['mode']);
    }

    /**
     * 通道是否已达任一限额（口径与 scanChannels 一致：按 create_time 计当日）。
     */
    protected function channelLimitReached($v, int $todayStart): bool
    {
        $cid        = (int) $v['id'];
        $allMoney   = (float) PayOrder::where(['channel_id' => $cid, 'status' => 1])->sum('money');
        $allOrder   = PayOrder::where(['channel_id' => $cid, 'status' => 1])->count();
        $todayMoney = (float) PayOrder::where(['channel_id' => $cid, 'status' => 1])->where('create_time', '>=', $todayStart)->sum('money');
        $todayOrder = PayOrder::where(['channel_id' => $cid, 'status' => 1])->where('create_time', '>=', $todayStart)->count();
        return ($v['all_money_max'] > 0 && $v['all_money_max'] <= $allMoney)
            || ($v['all_order_max'] > 0 && $v['all_order_max'] <= $allOrder)
            || ($v['today_money_max'] > 0 && $v['today_money_max'] <= $todayMoney)
            || ($v['today_order_max'] > 0 && $v['today_order_max'] <= $todayOrder);
    }

    /**
     * 按模式从候选中选一个：random 均匀 / weight 加权随机 / priority 最大权重(并列随机)。
     */
    protected function selectByMode(array $eligible, string $mode): array
    {
        if ($mode === 'priority') {
            $max = max(array_column($eligible, '_weight'));
            $top = array_values(array_filter($eligible, fn($e) => $e['_weight'] === $max));
            return $top[random_int(0, count($top) - 1)];
        }
        if ($mode === 'weight') {
            $total = array_sum(array_column($eligible, '_weight'));
            $r     = random_int(1, max(1, $total));
            $acc   = 0;
            foreach ($eligible as $e) {
                $acc += $e['_weight'];
                if ($r <= $acc) {
                    return $e;
                }
            }
            return $eligible[count($eligible) - 1];
        }
        // random
        return $eligible[random_int(0, count($eligible) - 1)];
    }

    /**
     * 遍历 polling=0 的通道，返回第一个绑定且未超限的
     */
    protected function scanChannels(int $uid, string $type, array $bindCtype, bool $ignoreBind = false): ?array
    {
        $list = PayChannel::where([
            'uid'       => $uid,
            'type'      => $type,
            'status'    => 1,
            'tt_switch' => 'true',
            'polling'   => 0,
        ])->order('id desc')->select();

        $todayStart = strtotime(date('Y-m-d'));
        $picked = null;
        foreach ($list as $v) {
            // 云端授权门禁：被云端精准禁用的通道不可用于下单/出码
            if (!\app\core\CloudGuard::isChannelAllowed((string) $v['c_type'])) {
                continue;
            }
            // 充值等场景 ignoreBind=true：不做套餐绑定过滤；否则按 bind_ctype 过滤
            if (!$ignoreBind && !in_array($v['c_type'], $bindCtype, true) && !$this->ctypeBound($v['c_type'], $bindCtype)) {
                continue;
            }
            $allMoney   = (float) PayOrder::where(['channel_id' => $v['id'], 'status' => 1])->sum('money');
            $allOrder   = PayOrder::where(['channel_id' => $v['id'], 'status' => 1])->count();
            $todayMoney = (float) PayOrder::where(['channel_id' => $v['id'], 'status' => 1])->where('create_time', '>=', $todayStart)->sum('money');
            $todayOrder = PayOrder::where(['channel_id' => $v['id'], 'status' => 1])->where('create_time', '>=', $todayStart)->count();

            if ($v['all_money_max'] > 0 && $v['all_money_max'] <= $allMoney) continue;
            if ($v['all_order_max'] > 0 && $v['all_order_max'] <= $allOrder) continue;
            if ($v['today_money_max'] > 0 && $v['today_money_max'] <= $todayMoney) continue;
            if ($v['today_order_max'] > 0 && $v['today_order_max'] <= $todayOrder) continue;

            $picked = $v->toArray(); // 旧逻辑取最后一个符合的（继续遍历覆盖）
        }
        return $picked;
    }

    /**
     * 兼容旧 bind_ctype 为逗号串 / 子串包含 的判断
     */
    protected function ctypeBound(string $cType, array $bindCtype): bool
    {
        foreach ($bindCtype as $b) {
            if (is_string($b) && $cType !== '' && strpos($b, $cType) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 金额去重：白名单通道 price=money；否则同通道未支付 price 已占用则 +0.01 递增
     */
    /**
     * 计算下单实付金额（去重唯一，避免同通道同 price 并发导致结算错配）。
     *
     * - no_increment_ctypes（官方/收款单等）：精确金额，不浮动。
     * - 商户配置了上浮区间（floatMax>0）：price = money + 随机[floatMin,floatMax]分，避开同通道 status=0 已占价。
     * - 未配置上浮：精确金额；冲突则逐分 +0.01 递增（原行为）。
     */
    protected function resolvePrice(array $channel, string $money, float $floatMin = 0, float $floatMax = 0): string
    {
        $noIncrement = Config::get('payment.no_increment_ctypes', []);
        if (in_array($channel['c_type'], $noIncrement, true)) {
            return $money;
        }

        $taken = fn($p) => (bool) PayOrder::where(['price' => $p, 'channel_id' => $channel['id'], 'status' => 0])->find();
        $baseCents = (int) round(((float) $money) * 100);

        // 区间随机上浮
        $minC = (int) round(max(0, $floatMin) * 100);
        $maxC = (int) round(max(0, $floatMax) * 100);
        if ($maxC > 0) {
            if ($minC > $maxC) $minC = 0;
            // 先随机若干次
            for ($i = 0; $i < 30; $i++) {
                $price = (string) round(($baseCents + random_int($minC, $maxC)) / 100, 2);
                if (!$taken($price)) {
                    return $price;
                }
            }
            // 兜底：区间内逐分扫描
            for ($c = $minC; $c <= $maxC; $c++) {
                $price = (string) round(($baseCents + $c) / 100, 2);
                if (!$taken($price)) {
                    return $price;
                }
            }
            throw new PayException('当前通道排队金额过多，请稍后再试');
        }

        // 未配置上浮：精确 + 冲突逐分递增
        if (!$taken($money)) {
            return $money;
        }
        $price = (float) $money;
        for ($i = 0; $i < 1000; $i++) {
            $price = round($price + 0.01, 2);
            if (!$taken((string) $price)) {
                return (string) $price;
            }
        }
        throw new PayException('当前通道排队金额过多，请稍后再试');
    }

    /**
     * 调用通道驱动出码，写入订单 qr_url / jump_url（供收银台展示）。
     * 失败不阻断下单（收银台会显示"等待生成"，由监控/重试补齐）。
     */
    protected function fillPayQr(string $tradeNo, array $channel, array $orderData): void
    {
        $cType = $channel['c_type'] ?? '';
        if (!$cType || !PaymentManager::has($cType)) {
            return;
        }
        try {
            $config = PayChannel::decryptConfig($channel['config'] ?? '');
            $res = PaymentManager::make($cType)->getPayQr($orderData, $config);
            if (($res['code'] ?? 0) == 1 || !empty($res['qr'])) {
                $update = [];
                if (!empty($res['qr'])) {
                    // type=url 视为跳转链接，否则二维码内容
                    if (($res['type'] ?? 'qr') === 'url') {
                        $update['jump_url'] = $res['qr'];
                    } else {
                        $update['qr_url'] = $res['qr'];
                    }
                }
                // 部分通道出码返回订单级配对键(如收款单 receipt_id / 即易付 orderNo)，
                // 写入 order.config 供结算按 (uid,channel_id,price,config) 精确匹配账单。
                if (isset($res['config']) && $res['config'] !== '' && $res['config'] !== false) {
                    $update['config'] = (string) $res['config'];
                }
                if ($update) {
                    PayOrder::where('trade_no', $tradeNo)->update($update);
                }
            } else {
                // 出码失败时记录错误信息到 order.param 供排查（仅当用户未传 param 时覆盖）
                $errMsg = $res['msg'] ?? '出码失败';
                PayOrder::where('trade_no', $tradeNo)->where('status', 0)
                    ->where('param', '')->update(['param' => '[出码失败] ' . $errMsg]);
            }
        } catch (\Throwable $e) {
            // 记录异常信息（仅当用户未传 param 时覆盖）
            PayOrder::where('trade_no', $tradeNo)->where('status', 0)
                ->where('param', '')->update(['param' => '[出码异常] ' . $e->getMessage()]);
        }
    }

    /* ---------------- Redis 锁 ---------------- */

    protected function acquireLock(string $key, int $ttl): string
    {
        $token = bin2hex(random_bytes(8));
        try {
            $redis = Cache::store('redis')->handler();
            $deadline = microtime(true) + 3; // 最多等 3s
            do {
                if ($redis->set($key, $token, ['nx', 'ex' => $ttl])) {
                    return $token;
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
        } catch (\Throwable $e) {
            // Redis 不可用则退化为无锁（单机仍正确）
        }
        return $token;
    }

    protected function releaseLock(string $key, string $token): void
    {
        try {
            $redis = Cache::store('redis')->handler();
            if ($redis->get($key) === $token) {
                $redis->del($key);
            }
        } catch (\Throwable $e) {
        }
    }
}
